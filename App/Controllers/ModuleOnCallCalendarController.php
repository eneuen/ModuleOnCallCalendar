<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\App\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MikoPBX\AdminCabinet\Controllers\BaseController;
use MikoPBX\AdminCabinet\Providers\AssetProvider;
use MikoPBX\Common\Models\IncomingRoutingTable;
use MikoPBX\Common\Providers\AclProvider;
use MikoPBX\Common\Providers\JwtProvider;
use MikoPBX\Core\System\SystemMessages;
use MikoPBX\Core\Workers\Libs\WorkerModelsEvents\Actions\ReloadDialplanAction;
use MikoPBX\Core\Workers\WorkerModelsEvents;
use MikoPBX\PBXCoreREST\Lib\Extensions\DropdownsAction;
use Modules\ModuleOnCallCalendar\Lib\Databases;
use Modules\ModuleOnCallCalendar\Lib\DutyResolver;
use Modules\ModuleOnCallCalendar\Lib\DutySegment;
use Modules\ModuleOnCallCalendar\Lib\EditorException;
use Modules\ModuleOnCallCalendar\Lib\Employees;
use Modules\ModuleOnCallCalendar\Lib\ModuleContext;
use Modules\ModuleOnCallCalendar\Lib\OnCallCalendarConf;
use Modules\ModuleOnCallCalendar\Lib\Rights;
use Modules\ModuleOnCallCalendar\Lib\RouteLinker;
use Modules\ModuleOnCallCalendar\Lib\ScheduleEditor;
use Modules\ModuleOnCallCalendar\Lib\ScheduleNumbers;
use Modules\ModuleOnCallCalendar\Lib\ScheduleRepository;
use Modules\ModuleOnCallCalendar\Lib\ScheduleStatus;
use Modules\ModuleOnCallCalendar\Lib\Templates;
use PDO;
use Phalcon\Mvc\Dispatcher;
use Throwable;

/**
 * Admin-cabinet page and JSON actions for On Call Calendar.
 *
 * Every request is checked against MikoPBX's access list for this
 * controller and action, as the core's SecurityPlugin does: administrators
 * may do everything; other roles get what an access group grants them (see
 * Lib/Rights for the actions behind View, Edit duty and Edit settings). The
 * core's beforeExecuteRoute() returns void, so a refused request is
 * forwarded to deniedAction() rather than stopped.
 *
 * Read actions are AJAX GETs. Write actions are AJAX POSTs with a JSON body
 * and a valid admin Bearer token, because the login cookie alone, as a
 * cross-site form would send it, must not change anything. All validation is in
 * ScheduleEditor and RouteLinker; refusals come back translated. After a
 * change the worker is asked to publish who is on duty, and the dialplan
 * is reloaded when a schedule's number or chain changed.
 */
class ModuleOnCallCalendarController extends BaseController
{
    private const string DENIED_ACTION = 'denied';

    /** The longest range the calendar may ask for. */
    private const int MAX_CALENDAR_DAYS = 62;

    public function beforeExecuteRoute(Dispatcher $dispatcher): void
    {
        parent::beforeExecuteRoute($dispatcher);

        if ($dispatcher->getActionName() === self::DENIED_ACTION) {
            return;
        }
        if (!$this->roleMay($this->currentRole(), $dispatcher->getActionName())) {
            $dispatcher->forward(['action' => self::DENIED_ACTION]);
        }
    }

    /** The module page. No data goes through Volt; the page loads it as JSON. */
    public function indexAction(): void
    {
        $base = 'js/cache/' . OnCallCalendarConf::MODULE_ID;
        $this->assets->collection(AssetProvider::SEMANTIC_UI_CSS)
            ->addCss('css/vendor/semantic/modal.min.css', true)
            ->addCss('css/vendor/semantic/card.min.css', true);
        $this->assets->collection(AssetProvider::SEMANTIC_UI_JS)
            ->addJs('js/vendor/semantic/modal.min.js', true);
        $this->assets->collection(AssetProvider::HEADER_CSS)
            ->addCss('css/cache/' . OnCallCalendarConf::MODULE_ID . '/on-call-calendar.css', true);
        $this->assets->collection(AssetProvider::FOOTER_JS)
            ->addJs("$base/vendor/fullcalendar.min.js", true)
            ->addJs("$base/on-call-calendar.js", true);
    }

    /** 403 for anyone who is not an admin: JSON for AJAX, a page otherwise. */
    public function deniedAction(): void
    {
        $this->response->setStatusCode(403, 'Forbidden');
        if ($this->request->isAjax()) {
            $this->view->setVar('raw_response', json_encode([
                'success' => false,
                'message' => $this->translation->_('occ_AccessDenied'),
            ]));
        }
    }

    // ------------------------------------------------------------------ reads

    /** GET overview: every schedule with who is on duty now, what's next, gaps and what routes to it. */
    public function overviewAction(): void
    {
        $this->json(function (): array {
            [$module, $core, $tz, $now] = [$this->moduleDb(), $this->coreDb(), $this->timezone(), time()];
            $repository = new ScheduleRepository($module);
            $directory = Employees::directory($core);
            $linker = $this->linker($module, $core);
            $since = self::local($now - 86400, $tz);
            $schedules = [];
            foreach ($repository->schedules() as $schedule) {
                $id = (int)$schedule['id'];
                $schedules[] = [
                    'schedule'   => $schedule,
                    'members'    => $repository->members($id, $directory),
                    'status'     => ScheduleStatus::of($repository->plan($id, $directory, $since), $tz, $now),
                    'references' => $linker->references((string)$schedule['number']),
                ];
            }

            return ['schedules' => $schedules] + $this->clock($tz, $now);
        });
    }

    /** GET schedule: id. Everything the schedule's tabs edit. */
    public function scheduleAction(): void
    {
        $this->json(function (): array {
            [$module, $core] = [$this->moduleDb(), $this->coreDb()];
            $repository = new ScheduleRepository($module);
            $id = $this->queryInt('id');
            $schedule = $repository->schedule($id) ?? throw new EditorException('occ_ErrNotFound');
            $linker = $this->linker($module, $core);

            return [
                'schedule'          => $schedule,
                'members'           => $repository->members($id, Employees::directory($core)),
                'shift_types'       => $repository->shiftTypes($id),
                'rosters'           => $repository->rosters($id),
                'rotations'         => $repository->rotations($id),
                'routes'            => $linker->routes(),
                'references'        => $linker->references((string)$schedule['number']),
                'needed_timeout'    => $linker->neededTimeout($schedule),
                'elsewhere_targets' => ScheduleEditor::scheduleDestinations($schedule),
            ] + $this->clock($this->timezone(), time());
        });
    }

    /** GET calendar: id, from, to ("Y-m-d", to exclusive). The decisions and overrides in that range. */
    public function calendarAction(): void
    {
        $this->json(function (): array {
            [$module, $core, $tz] = [$this->moduleDb(), $this->coreDb(), $this->timezone()];
            $repository = new ScheduleRepository($module);
            $id = $this->queryInt('id');
            $repository->schedule($id) ?? throw new EditorException('occ_ErrNotFound');
            $from = self::dayStart((string)$this->request->getQuery('from'), $tz);
            $to = self::dayStart((string)$this->request->getQuery('to'), $tz);
            if ($to <= $from || $to - $from > self::MAX_CALENDAR_DAYS * 86400 + 3600) {
                throw new EditorException('occ_ErrInvalid');
            }
            $plan = $repository->plan($id, Employees::directory($core));
            $resolver = new DutyResolver($plan, $tz);
            $toLocal = self::local($to, $tz);
            $overrides = array_values(array_filter(
                $repository->overrides($id, self::local($from, $tz)),
                static fn (array $o): bool => $o['start_at'] < $toLocal
            ));

            return [
                'segments'  => array_map(static fn (DutySegment $s): array => $s->toArray(), $resolver->segments($from, $to)),
                'overrides' => $overrides,
            ] + $this->clock($tz, time());
        });
    }

    /** GET options: the PBX objects the forms pick from. */
    public function optionsAction(): void
    {
        $this->json(function (): array {
            $core = $this->coreDb();
            $employees = [];
            foreach (Employees::directory($core) as $userId => $employee) {
                $employees[] = ['id' => $userId] + $employee;
            }
            $queues = $core->query('SELECT extension AS number, name FROM m_CallQueues ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
            $sounds = $core->query("SELECT id, name FROM m_SoundFiles WHERE category = 'custom' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
            $destinations = [];
            $result = DropdownsAction::getForSelect('routing');
            foreach ($result->success ? $result->data : [] as $row) {
                $value = (string)($row['value'] ?? '');
                if ($value !== '') {
                    $name = trim(html_entity_decode(strip_tags((string)($row['name'] ?? $value)), ENT_QUOTES));
                    // Employees appear with their extension and their mobile; the number tells them apart.
                    if (ctype_digit($value) && !str_contains($name, $value)) {
                        $name .= " ($value)";
                    }
                    $destinations[] = ['value' => $value, 'name' => $name, 'type' => (string)($row['type'] ?? '')];
                }
            }
            $editor = new ScheduleEditor($this->moduleDb(), $core, '', time());

            return [
                'employees'        => $employees,
                'queues'           => $queues,
                'sounds'           => $sounds,
                'destinations'     => $destinations,
                'suggested_number' => $editor->suggestNumber(),
                'routes'           => $this->linker($this->moduleDb(), $core)->routes(),
                'oncall_hours'     => Templates::ONCALL_ACTIVE_HOURS,
                // The changes this person may make; the page shows only those controls.
                'can'              => Rights::writable(fn (string $action): bool => $this->roleMay($this->currentRole(), $action)),
            ] + $this->clock($this->timezone(), time());
        });
    }

    /** GET audit: id (0 = all schedules). The latest 200 changes. */
    public function auditAction(): void
    {
        $this->json(function (): array {
            $id = (int)$this->request->getQuery('id');
            $sql = 'SELECT * FROM ' . ScheduleRepository::AUDIT . ($id > 0 ? ' WHERE schedule_id = ?' : '') . ' ORDER BY id DESC LIMIT 200';
            $stmt = $this->moduleDb()->prepare($sql);
            $stmt->execute($id > 0 ? [$id] : []);
            $entries = array_map(static fn (array $r): array => [
                'id' => (int)$r['id'], 'schedule_id' => $r['schedule_id'] === null ? null : (int)$r['schedule_id'],
                'actor' => (string)$r['actor'], 'action' => (string)$r['action'], 'at' => (int)$r['at'],
                'before' => $r['before_json'] === null ? null : json_decode((string)$r['before_json'], true),
                'after' => $r['after_json'] === null ? null : json_decode((string)$r['after_json'], true),
            ], $stmt->fetchAll(PDO::FETCH_ASSOC));

            return ['entries' => $entries];
        });
    }

    // ------------------------------------------------------------------ schedule writes

    /**
     * POST createSchedule: name, number, template, chain fields, shift_names,
     * rotation, route_ids (incoming routes to link).
     */
    public function createScheduleAction(): void
    {
        $this->write(function (ScheduleEditor $editor, array $data, PDO $module, PDO $core): array {
            $id = $editor->createSchedule($data);
            $schedule = (new ScheduleRepository($module))->schedule($id);
            try {
                ScheduleNumbers::register((string)$schedule['number'], (string)$schedule['name']);
            } catch (Throwable $e) {
                $editor->deleteSchedule($id);
                SystemMessages::sysLogMsg(self::class, 'Schedule number not registered: ' . $e->getMessage(), LOG_WARNING);
                throw new EditorException('occ_ErrNumberTaken', ['number' => (string)$schedule['number']]);
            }
            // The schedule exists from here on, so routes are linked one by one and failures reported, not thrown.
            $linker = $this->linker($module, $core);
            $linkErrors = [];
            foreach (is_array($data['route_ids'] ?? null) ? $data['route_ids'] : [] as $routeId) {
                try {
                    $linker->link($schedule, (int)$routeId);
                } catch (EditorException $e) {
                    $linkErrors[] = $this->translation->_($e->key, $e->params);
                }
            }

            return ['id' => $id, 'link_errors' => $linkErrors];
        }, true);
    }

    /** POST updateSchedule: id, and the fields to change (see ScheduleEditor::updateSchedule). */
    public function updateScheduleAction(): void
    {
        $this->write(function (ScheduleEditor $editor, array $data, PDO $module, PDO $core): array {
            $id = (int)($data['id'] ?? 0);
            $before = (new ScheduleRepository($module))->schedule($id);
            $editor->updateSchedule($id, $data);
            $after = (new ScheduleRepository($module))->schedule($id);
            if ($before !== null && $after !== null && $before['name'] !== $after['name']) {
                ScheduleNumbers::register((string)$after['number'], (string)$after['name']);
            }
            if ($after !== null) {
                // Linked routes must still keep the call long enough for the changed chain.
                $this->linker($module, $core)->refreshTimeouts($after);
            }

            return ['id' => $id];
        }, true);
    }

    /**
     * POST deleteSchedule: id. Refused while anything other than the routes
     * the module linked still sends calls to the number; linked routes are
     * restored first.
     */
    public function deleteScheduleAction(): void
    {
        $this->write(function (ScheduleEditor $editor, array $data, PDO $module, PDO $core): array {
            $id = (int)($data['id'] ?? 0);
            $schedule = (new ScheduleRepository($module))->schedule($id) ?? throw new EditorException('occ_ErrNotFound');
            $linker = $this->linker($module, $core);
            $linked = array_column(array_filter($linker->routes(), static fn (array $r): bool => $r['linked_schedule_id'] === $id
                && !$r['changed_outside']), 'id');
            $others = array_filter($linker->references((string)$schedule['number']),
                static fn (array $r): bool => !($r['kind'] === 'route' && in_array($r['id'], $linked, true)));
            if ($others !== []) {
                throw new EditorException('occ_ErrStillRouted', ['list' => $this->describeReferences($others)]);
            }
            $linker->unlinkAll($id);
            // A route that couldn't be put back (it pointed here before it was linked) still sends calls here.
            $left = $linker->references((string)$schedule['number']);
            if ($left !== []) {
                throw new EditorException('occ_ErrStillRouted', ['list' => $this->describeReferences($left)]);
            }
            // The schedule first: its number can't be deleted while the schedule exists (see Schedule::getDynamicRelations()).
            $editor->deleteSchedule($id);
            $reasons = ScheduleNumbers::unregister((string)$schedule['number']);
            if ($reasons !== []) {
                SystemMessages::sysLogMsg(self::class, "Schedule {$schedule['number']} deleted, but its number stays: " . implode(' ', $reasons), LOG_WARNING);
            }

            return [];
        }, true);
    }

    /** POST linkRoute: id (schedule), route_id. */
    public function linkRouteAction(): void
    {
        $this->write(function (ScheduleEditor $editor, array $data, PDO $module, PDO $core): array {
            $schedule = (new ScheduleRepository($module))->schedule((int)($data['id'] ?? 0)) ?? throw new EditorException('occ_ErrNotFound');
            $this->linker($module, $core)->link($schedule, (int)($data['route_id'] ?? 0));

            return [];
        });
    }

    /** POST unlinkRoute: id (schedule), route_id. Returns whether the route was put back. */
    public function unlinkRouteAction(): void
    {
        $this->write(function (ScheduleEditor $editor, array $data, PDO $module, PDO $core): array {
            return ['restored' => $this->linker($module, $core)->unlink((int)($data['id'] ?? 0), (int)($data['route_id'] ?? 0))];
        });
    }

    /**
     * POST testCall: id (schedule), extension. Rings the extension, then
     * calls the schedule from it through its real chain.
     */
    public function testCallAction(): void
    {
        $this->write(function (ScheduleEditor $editor, array $data, PDO $module, PDO $core): array {
            $schedule = (new ScheduleRepository($module))->schedule((int)($data['id'] ?? 0)) ?? throw new EditorException('occ_ErrNotFound');
            $extension = (string)($data['extension'] ?? '');
            if (!in_array($extension, array_column(Employees::directory($core), 'extension'), true)) {
                throw new EditorException('occ_ErrNoExtension');
            }
            OnCallCalendarConf::requestTestCall((int)$schedule['id'], $extension);

            return [];
        });
    }

    /**
     * POST setRuleBackup: id (schedule), rule ('roster' or 'rotation'),
     * rule_id, backup_type, backup_number, backup_user_id, backup_ring_seconds.
     * A rule's backup decides where calls can go, so it is a settings right.
     */
    public function setRuleBackupAction(): void
    {
        $this->write(function (ScheduleEditor $editor, array $data, PDO $module, PDO $core): array {
            $id = (int)($data['id'] ?? 0);
            $editor->setRuleBackup($id, $data);
            $schedule = (new ScheduleRepository($module))->schedule($id);
            if ($schedule !== null) {
                $this->linker($module, $core)->refreshTimeouts($schedule);
            }

            return [];
        });
    }

    // ------------------------------------------------------------------ plan writes

    /** POST saveShiftType: id (schedule), shift (name, start, end, weekdays, id to change one). */
    public function saveShiftTypeAction(): void
    {
        $this->write(fn (ScheduleEditor $e, array $d): array => ['id' => $e->saveShiftType((int)($d['id'] ?? 0), (array)($d['shift'] ?? []))]);
    }

    /** POST deleteShiftType: id (schedule), shift_type_id. */
    public function deleteShiftTypeAction(): void
    {
        $this->write(function (ScheduleEditor $e, array $d): array {
            $e->deleteShiftType((int)($d['id'] ?? 0), (int)($d['shift_type_id'] ?? 0));

            return [];
        });
    }

    /** POST orderShiftTypes: id (schedule), ids. */
    public function orderShiftTypesAction(): void
    {
        $this->write(function (ScheduleEditor $e, array $d): array {
            $e->orderShiftTypes((int)($d['id'] ?? 0), array_map('intval', (array)($d['ids'] ?? [])));

            return [];
        });
    }

    /** POST saveRoster: id (schedule), roster (valid_from, valid_until, cycle_weeks, id to change one). */
    public function saveRosterAction(): void
    {
        $this->write(fn (ScheduleEditor $e, array $d): array => ['id' => $e->saveRoster((int)($d['id'] ?? 0), (array)($d['roster'] ?? []))]);
    }

    /** POST deleteRoster: id (schedule), roster_id. */
    public function deleteRosterAction(): void
    {
        $this->write(function (ScheduleEditor $e, array $d): array {
            $e->deleteRoster((int)($d['id'] ?? 0), (int)($d['roster_id'] ?? 0));

            return [];
        });
    }

    /** POST setCell: id (schedule), roster_id, shift_type_id, week, weekday, user_ids. */
    public function setCellAction(): void
    {
        $this->write(function (ScheduleEditor $e, array $d): array {
            $e->setCell((int)($d['id'] ?? 0), (int)($d['roster_id'] ?? 0), (int)($d['shift_type_id'] ?? 0), (int)($d['week'] ?? 0),
                (int)($d['weekday'] ?? 0), (array)($d['user_ids'] ?? []));

            return [];
        });
    }

    /** POST saveRotation: id (schedule), rotation (see ScheduleEditor::saveRotation). */
    public function saveRotationAction(): void
    {
        $this->write(fn (ScheduleEditor $e, array $d): array => ['id' => $e->saveRotation((int)($d['id'] ?? 0), (array)($d['rotation'] ?? []))]);
    }

    /** POST deleteRotation: id (schedule), rotation_id. */
    public function deleteRotationAction(): void
    {
        $this->write(function (ScheduleEditor $e, array $d): array {
            $e->deleteRotation((int)($d['id'] ?? 0), (int)($d['rotation_id'] ?? 0));

            return [];
        });
    }

    /** POST orderRules: id (schedule), rules (list of {type, id}). */
    public function orderRulesAction(): void
    {
        $this->write(function (ScheduleEditor $e, array $d): array {
            $e->orderRules((int)($d['id'] ?? 0), array_values(array_filter((array)($d['rules'] ?? []), 'is_array')));

            return [];
        });
    }

    /** POST addOverride: id (schedule), override (kind, start_at, end_at, user_ids, destination, note). */
    public function addOverrideAction(): void
    {
        $this->write(fn (ScheduleEditor $e, array $d): array => ['id' => $e->addOverride((int)($d['id'] ?? 0), (array)($d['override'] ?? []))]);
    }

    /** POST addSwap: id (schedule), swap (user_a, start_a, end_a, user_b, start_b, end_b, note). */
    public function addSwapAction(): void
    {
        $this->write(fn (ScheduleEditor $e, array $d): array => ['ids' => $e->addSwap((int)($d['id'] ?? 0), (array)($d['swap'] ?? []))]);
    }

    /** POST updateOverride: id (schedule), override_id, override (fields to change). */
    public function updateOverrideAction(): void
    {
        $this->write(function (ScheduleEditor $e, array $d): array {
            $e->updateOverride((int)($d['id'] ?? 0), (int)($d['override_id'] ?? 0), (array)($d['override'] ?? []));

            return [];
        });
    }

    /** POST deleteOverride: id (schedule), override_id. */
    public function deleteOverrideAction(): void
    {
        $this->write(function (ScheduleEditor $e, array $d): array {
            $e->deleteOverride((int)($d['id'] ?? 0), (int)($d['override_id'] ?? 0));

            return [];
        });
    }

    /** POST removeMember: id (schedule), member_id. */
    public function removeMemberAction(): void
    {
        $this->write(function (ScheduleEditor $e, array $d): array {
            $e->removeMember((int)($d['id'] ?? 0), (int)($d['member_id'] ?? 0));

            return [];
        });
    }

    // ------------------------------------------------------------------ plumbing

    /**
     * Runs $build and sends its result as JSON. Refusals get 400 with the
     * translated reason; anything else a generic 500 and a log entry.
     */
    private function json(callable $build): void
    {
        $status = 200;
        try {
            if (!$this->request->isGet() || !$this->request->isAjax()) {
                throw new InvalidArgumentException('Use an AJAX GET request.');
            }
            $body = ['success' => true, 'data' => $build()];
        } catch (EditorException $e) {
            $status = 400;
            $body = ['success' => false, 'message' => $this->translation->_($e->key, $e->params)];
        } catch (InvalidArgumentException) {
            $status = 400;
            $body = ['success' => false, 'message' => $this->translation->_('occ_ErrInvalid')];
        } catch (Throwable $e) {
            $status = 500;
            $body = ['success' => false, 'message' => $this->translation->_('occ_ErrLoad')];
            SystemMessages::sysLogMsg(self::class, 'Request failed: ' . $e->getMessage(), LOG_ERR);
        }
        $this->send($status, $body);
    }

    /**
     * Runs $change for a write action: AJAX POST, JSON body, Bearer token.
     * Afterwards the worker publishes who is on duty; with $reload the
     * dialplan is regenerated too.
     *
     * @param callable(ScheduleEditor, array<string, mixed>, PDO, PDO): array<string, mixed> $change
     */
    private function write(callable $change, bool $reload = false): void
    {
        // The token itself must grant the action: the role check above also accepts the login cookie,
        // which a cross-site request would carry.
        $auth = (string)$this->request->getHeader('Authorization');
        $tokenRole = $this->di->getShared(JwtProvider::SERVICE_NAME)->extractRoleFromHeader($auth === '' ? null : $auth);
        if (!$this->request->isPost() || !$this->request->isAjax() || !$this->roleMay($tokenRole, $this->dispatcher->getActionName())) {
            $this->send(403, ['success' => false, 'message' => $this->translation->_('occ_AccessDenied')]);

            return;
        }
        $status = 200;
        try {
            $data = $this->request->getJsonRawBody(true);
            if (!is_array($data)) {
                throw new EditorException('occ_ErrInvalid');
            }
            $module = Databases::openWritable(ModuleContext::moduleDbFile());
            $core = $this->coreDb();
            $editor = new ScheduleEditor($module, $core, $this->currentLogin(), time());
            $body = ['success' => true, 'data' => $change($editor, $data, $module, $core)];
            OnCallCalendarConf::requestPublish();
            if ($reload) {
                WorkerModelsEvents::invokeAction(ReloadDialplanAction::class);
            }
        } catch (EditorException $e) {
            $status = 400;
            $body = ['success' => false, 'message' => $this->translation->_($e->key, $e->params)];
        } catch (Throwable $e) {
            $status = 500;
            $body = ['success' => false, 'message' => $this->translation->_('occ_ErrSave')];
            SystemMessages::sysLogMsg(self::class, 'Write failed: ' . $e->getMessage(), LOG_ERR);
        }
        $this->send($status, $body);
    }

    /** @param array<string, mixed> $body */
    private function send(int $status, array $body): void
    {
        $this->response->setStatusCode($status);
        $this->view->setVar('raw_response', json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function linker(PDO $module, PDO $core): RouteLinker
    {
        // The core's route model checks that the destination exists, and its
        // save triggers the dialplan reload. (The REST save action needs
        // services that only exist in the core's API worker.)
        $save = static function (int $routeId, string $extension, int $timeout): ?string {
            $route = IncomingRoutingTable::findFirstById($routeId);
            if ($route === null) {
                return 'not found';
            }
            $route->extension = $extension;
            $route->timeout = (string)$timeout;

            return $route->save() ? null : (implode(' ', array_map('strval', $route->getMessages())) ?: 'unknown error');
        };

        return new RouteLinker($module, $core, $save, $this->currentLogin(), time());
    }

    /** @param list<array{kind: string, id: int, name: string}>|array<int, array{kind: string, id: int, name: string}> $references */
    private function describeReferences(array $references): string
    {
        return implode('; ', array_map(
            fn (array $r): string => $this->translation->_('occ_RefKind_' . $r['kind']) . ' "' . $r['name'] . '"',
            $references
        ));
    }

    private function moduleDb(): PDO
    {
        return Databases::openReadOnly(ModuleContext::moduleDbFile());
    }

    private function coreDb(): PDO
    {
        return Databases::openReadOnly(ModuleContext::coreDbFile());
    }

    private function timezone(): DateTimeZone
    {
        return ModuleContext::timezone($this->coreDb());
    }

    /** @return array{now: int, timezone: string, today: string} */
    private function clock(DateTimeZone $tz, int $now): array
    {
        return ['now' => $now, 'timezone' => $tz->getName(), 'today' => self::local($now, $tz, 'Y-m-d')];
    }

    private function queryInt(string $name): int
    {
        return (int)$this->request->getQuery($name);
    }

    private static function local(int $timestamp, DateTimeZone $tz, string $format = 'Y-m-d H:i'): string
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone($tz)->format($format);
    }

    private static function dayStart(string $date, DateTimeZone $tz): int
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz);
        if ($day === false || $day->format('Y-m-d') !== $date) {
            throw new EditorException('occ_ErrDate');
        }

        return $day->getTimestamp();
    }

    /** Whether a role may run one of this controller's actions, by MikoPBX's access list. */
    private function roleMay(?string $role, string $action): bool
    {
        if ($role === null || $role === '') {
            return false;
        }
        try {
            return (bool)$this->di->getShared(AclProvider::SERVICE_NAME)->isAllowed($role, self::class, $action);
        } catch (Throwable) {
            // An unknown role or action: refused.
            return false;
        }
    }

    /** The user's login from the Bearer token, for the audit log. */
    private function currentLogin(): string
    {
        $header = (string)$this->request->getHeader('Authorization');
        if (!str_starts_with($header, 'Bearer ')) {
            return '';
        }
        try {
            $payload = $this->di->getShared(JwtProvider::SERVICE_NAME)->validate(substr($header, 7));
        } catch (Throwable) {
            return '';
        }

        return is_array($payload) && is_string($payload['userId'] ?? null) ? $payload['userId'] : '';
    }

    /**
     * The caller's role, resolved the same way as the core's SecurityPlugin:
     * the Bearer token first, then the refresh-token cookie of a page load.
     */
    private function currentRole(): ?string
    {
        $jwt = $this->di->getShared(JwtProvider::SERVICE_NAME);

        $role = $jwt->extractRoleFromHeader($this->request->getHeader('Authorization'));
        if ($role !== null) {
            return $role;
        }

        if ($this->cookies->has('refreshToken')) {
            try {
                $refreshToken = $this->cookies->get('refreshToken')->getValue();
                if (!empty($refreshToken)) {
                    return $jwt->extractRoleFromRefreshToken($refreshToken);
                }
            } catch (Throwable) {
                // An unreadable cookie counts as no role: refused.
            }
        }

        return null;
    }
}
