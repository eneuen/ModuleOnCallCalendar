<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Lib;

use MikoPBX\Core\System\SystemMessages;
use PDO;

/**
 * Points incoming routes at a schedule's number, and back. The only change
 * the module makes to the core's routing.
 *
 * Linking records the route's previous destination and timeout, then sets
 * the destination to the schedule's number and raises the timeout so the
 * whole chain fits: the core stops the route's call at that timeout and
 * moves on to its next rule. Unlinking puts both back, but only while the
 * route still points at the schedule: if an admin changed it by hand, the
 * link is just forgotten, never overwritten.
 *
 * The core save itself is passed in ($saveRoute), so this class is tested
 * without the core. On the PBX it saves the core's route model, which
 * checks the destination exists and reloads the dialplan.
 */
final class RouteLinker
{
    /** Added to the chain's ring times when raising a route's timeout. */
    public const int TIMEOUT_MARGIN = 30;

    /** A queue's wait when it has no timeout of its own. */
    private const int QUEUE_DEFAULT_WAIT = 300;

    /**
     * @param callable(int, string, int): ?string $saveRoute route id, destination, timeout → error, or null when saved
     */
    public function __construct(
        private readonly PDO $module,
        private readonly PDO $core,
        private $saveRoute,
        private readonly string $actor,
        private readonly int $now,
    ) {
    }

    /**
     * Every incoming route (except the default action), with what it points
     * at and whether a schedule linked it.
     *
     * @return list<array{id: int, rulename: string, number: string, provider: string, extension: string, timeout: int,
     *     linked_schedule_id: ?int, changed_outside: bool}>
     */
    public function routes(): array
    {
        $links = [];
        foreach ($this->module->query('SELECT * FROM ' . ScheduleRepository::ROUTE_LINKS)->fetchAll(PDO::FETCH_ASSOC) as $link) {
            $links[(int)$link['route_id']] = $link;
        }
        $routes = [];
        $rows = $this->core->query('SELECT id, rulename, number, provider, extension, timeout FROM m_IncomingRoutingTable
            WHERE id != 1 AND COALESCE(priority, 0) != 9999 ORDER BY priority, id')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $link = $links[$id] ?? null;
            $routes[] = [
                'id' => $id, 'rulename' => (string)$row['rulename'], 'number' => (string)$row['number'],
                'provider' => (string)$row['provider'], 'extension' => (string)$row['extension'], 'timeout' => (int)$row['timeout'],
                'linked_schedule_id' => $link === null ? null : (int)$link['schedule_id'],
                'changed_outside' => $link !== null && (string)$row['extension'] !== (string)$link['linked_extension'],
            ];
        }

        return $routes;
    }

    /**
     * How long a route must keep the call for this schedule's chain:
     * step 1, plus the longest backup (the schedule's or a rule's: a
     * person's ring time or a queue's wait), plus a margin.
     *
     * @param array<string, mixed> $schedule
     */
    public function neededTimeout(array $schedule): int
    {
        // The longest backup: the schedule's own, or a roster's or rotation's that replaces it.
        $backup = $this->backupSeconds($schedule);
        foreach ([ScheduleRepository::ROSTERS, ScheduleRepository::ROTATIONS] as $table) {
            $stmt = $this->module->prepare("SELECT backup_type, backup_number, backup_ring_seconds FROM $table WHERE schedule_id = ?");
            $stmt->execute([(int)$schedule['id']]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rule) {
                $backup = max($backup, $this->backupSeconds($rule));
            }
        }

        return (int)$schedule['ring_seconds'] + $backup + self::TIMEOUT_MARGIN;
    }

    /**
     * How long a backup can hold the call: a person's ring time, or a
     * queue's wait.
     *
     * @param array<string, mixed> $backup backup_type, backup_number, backup_ring_seconds
     */
    private function backupSeconds(array $backup): int
    {
        if ((string)($backup['backup_type'] ?? '') === 'member') {
            return (int)($backup['backup_ring_seconds'] ?? 0);
        }
        if ((string)($backup['backup_type'] ?? '') === 'queue') {
            $stmt = $this->core->prepare('SELECT timeout_to_redirect_to_extension FROM m_CallQueues WHERE extension = ?');
            $stmt->execute([(string)$backup['backup_number']]);
            $wait = (int)$stmt->fetchColumn();

            return $wait > 0 ? $wait : self::QUEUE_DEFAULT_WAIT;
        }

        return 0;
    }

    /**
     * Points a route at the schedule. A route linked to another schedule is
     * refused; linking again to the same schedule only refreshes the timeout.
     *
     * @param array<string, mixed> $schedule
     */
    public function link(array $schedule, int $routeId): void
    {
        $route = $this->route($routeId);
        $number = (string)$schedule['number'];
        $link = $this->findLink($routeId);
        if ($link !== null && (int)$link['schedule_id'] !== (int)$schedule['id']) {
            throw new EditorException('occ_ErrRouteLinkedElsewhere');
        }
        if ($link === null && (string)$route['extension'] === $number) {
            // Already sent here by hand: there is nothing to put back on unlink.
            throw new EditorException('occ_ErrRouteAlreadyHere');
        }
        $timeout = self::raisedTimeout((int)$route['timeout'], $this->neededTimeout($schedule));
        // The link is recorded before the route is changed and removed again if the change fails,
        // so a changed route always has a record to put it back from.
        if ($link === null) {
            $this->module->prepare('INSERT INTO ' . ScheduleRepository::ROUTE_LINKS . ' (schedule_id, route_id, previous_extension,
                previous_timeout, linked_extension, linked_timeout, linked_at, linked_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([(int)$schedule['id'], $routeId, (string)$route['extension'], (int)$route['timeout'], $number, $timeout,
                    $this->now, $this->actor]);
        }
        try {
            $this->save($routeId, $number, $timeout);
        } catch (EditorException $e) {
            if ($link === null) {
                $this->module->prepare('DELETE FROM ' . ScheduleRepository::ROUTE_LINKS . ' WHERE route_id = ?')->execute([$routeId]);
            }
            throw $e;
        }
        if ($link !== null) {
            $this->module->prepare('UPDATE ' . ScheduleRepository::ROUTE_LINKS . ' SET linked_timeout = ? WHERE route_id = ?')
                ->execute([$timeout, $routeId]);
        }
        $this->audit((int)$schedule['id'], 'route.link', ['route_id' => $routeId, 'extension' => $route['extension'], 'timeout' => (int)$route['timeout']],
            ['route_id' => $routeId, 'extension' => $number, 'timeout' => $timeout]);
    }

    /**
     * Saves a route through the core. A validation message from the core is
     * shown as the reason; any other failure is logged and shown as a plain
     * message, so internal details stay off the page.
     */
    private function save(int $routeId, string $extension, int $timeout): void
    {
        try {
            $error = ($this->saveRoute)($routeId, $extension, $timeout);
        } catch (\Throwable $e) {
            SystemMessages::sysLogMsg(self::class, "Route $routeId: " . $e->getMessage(), LOG_ERR);
            throw new EditorException('occ_ErrRouteSaveLog');
        }
        if ($error !== null) {
            throw new EditorException('occ_ErrRouteSave', ['reason' => $error]);
        }
    }

    /**
     * Puts a linked route back as it was, if it still points at the
     * schedule; either way the link is forgotten.
     *
     * @return bool whether the route was restored (false: it had been changed by hand)
     */
    public function unlink(int $scheduleId, int $routeId): bool
    {
        $link = $this->findLink($routeId);
        if ($link === null || (int)$link['schedule_id'] !== $scheduleId) {
            throw new EditorException('occ_ErrNotFound');
        }
        $route = $this->core->prepare('SELECT extension, timeout FROM m_IncomingRoutingTable WHERE id = ?');
        $route->execute([$routeId]);
        $row = $route->fetch(PDO::FETCH_ASSOC);
        $current = $row === false ? false : (string)$row['extension'];
        $restored = false;
        if ($row !== false && $current === (string)$link['linked_extension']) {
            // The timeout too, unless an admin changed it since the link.
            $timeout = (int)$row['timeout'] === (int)$link['linked_timeout'] ? (int)$link['previous_timeout'] : (int)$row['timeout'];
            $this->save($routeId, (string)$link['previous_extension'], $timeout);
            $restored = true;
        }
        $this->module->prepare('DELETE FROM ' . ScheduleRepository::ROUTE_LINKS . ' WHERE route_id = ?')->execute([$routeId]);
        $this->audit($scheduleId, 'route.unlink', ['route_id' => $routeId, 'extension' => $current === false ? null : (string)$current],
            ['route_id' => $routeId, 'extension' => $restored ? (string)$link['previous_extension'] : null, 'restored' => $restored]);

        return $restored;
    }

    /**
     * After a schedule's chain changed: raises the timeout of every route
     * linked to it (and still pointing at it) where the chain no longer fits.
     */
    public function refreshTimeouts(array $schedule): void
    {
        $needed = $this->neededTimeout($schedule);
        foreach ($this->routes() as $route) {
            if ($route['linked_schedule_id'] !== (int)$schedule['id'] || $route['changed_outside']) {
                continue;
            }
            $timeout = self::raisedTimeout($route['timeout'], $needed);
            if ($timeout !== $route['timeout']) {
                $this->link($schedule, $route['id']);
            }
        }
    }

    /** A route timeout long enough for the chain; 0 or empty (no limit) stays as it is. */
    private static function raisedTimeout(int $current, int $needed): int
    {
        return $current <= 0 ? $current : max($current, $needed);
    }

    /**
     * Unlinks every route of a schedule, before it is deleted.
     *
     * @return int how many routes were restored
     */
    public function unlinkAll(int $scheduleId): int
    {
        $stmt = $this->module->prepare('SELECT route_id FROM ' . ScheduleRepository::ROUTE_LINKS . ' WHERE schedule_id = ?');
        $stmt->execute([$scheduleId]);
        $restored = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $routeId) {
            $restored += $this->unlink($scheduleId, (int)$routeId) ? 1 : 0;
        }

        return $restored;
    }

    /**
     * Everything that sends calls to a schedule's number: incoming routes
     * (linked or set by hand), off-work time rules, IVR menu options and
     * timeouts, queue redirects, employees' forwarding, and this module's
     * other schedules (chain destinations and "send elsewhere" overrides).
     *
     * @return list<array{kind: string, id: int, name: string}>
     */
    public function references(string $number): array
    {
        $found = [];
        $queries = [
            'route'      => "SELECT id, COALESCE(NULLIF(rulename, ''), number) AS name FROM m_IncomingRoutingTable WHERE extension = ?",
            'offwork'    => "SELECT id, COALESCE(NULLIF(description, ''), id) AS name FROM m_OutWorkTimes WHERE extension = ?",
            'ivr'        => 'SELECT DISTINCT m.id, m.name FROM m_IvrMenu m LEFT JOIN m_IvrMenuActions a ON a.ivr_menu_id = m.uniqid
                             WHERE m.timeout_extension = ? OR a.extension = ?',
            'queue'      => 'SELECT id, name FROM m_CallQueues WHERE timeout_extension = ? OR redirect_to_extension_if_empty = ?
                             OR redirect_to_extension_if_unanswered = ? OR redirect_to_extension_if_repeat_exceeded = ?',
            'forwarding' => 'SELECT id, extension AS name FROM m_ExtensionForwardingRights WHERE forwarding = ? OR forwardingonbusy = ?
                             OR forwardingonunavailable = ?',
        ];
        foreach ($queries as $kind => $sql) {
            try {
                $stmt = $this->core->prepare($sql);
                $stmt->execute(array_fill(0, substr_count($sql, '?'), $number));
            } catch (\PDOException) {
                // A table this PBX version doesn't have: nothing of that kind points here.
                continue;
            }
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $found[] = ['kind' => $kind, 'id' => (int)$row['id'], 'name' => (string)$row['name']];
            }
        }
        // Other schedules of this module: their chain, and "send elsewhere" overrides not yet over.
        $stmt = $this->module->prepare('SELECT DISTINCT s.id, s.name FROM ' . ScheduleRepository::SCHEDULES . ' s
            LEFT JOIN ' . ScheduleRepository::OVERRIDES . " o ON o.schedule_id = s.id AND o.kind = 'elsewhere' AND o.destination = ?
            WHERE s.number != ? AND (s.final_number = ? OR s.outside_number = ? OR (s.backup_type = 'queue' AND s.backup_number = ?)
                OR o.id IS NOT NULL)");
        $stmt->execute([$number, $number, $number, $number, $number]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $found[] = ['kind' => 'schedule', 'id' => (int)$row['id'], 'name' => (string)$row['name']];
        }

        return $found;
    }

    /** @return array<string, mixed> */
    private function route(int $routeId): array
    {
        $stmt = $this->core->prepare('SELECT * FROM m_IncomingRoutingTable WHERE id = ?');
        $stmt->execute([$routeId]);
        $route = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($route === false || $routeId === 1 || (int)$route['priority'] === 9999) {
            throw new EditorException('occ_ErrNotFound');
        }

        return $route;
    }

    /** @return array<string, mixed>|null */
    private function findLink(int $routeId): ?array
    {
        $stmt = $this->module->prepare('SELECT * FROM ' . ScheduleRepository::ROUTE_LINKS . ' WHERE route_id = ?');
        $stmt->execute([$routeId]);
        $link = $stmt->fetch(PDO::FETCH_ASSOC);

        return $link === false ? null : $link;
    }

    private function audit(int $scheduleId, string $action, mixed $before, mixed $after): void
    {
        $this->module->prepare('INSERT INTO ' . ScheduleRepository::AUDIT . ' (schedule_id, actor, action, before_json, after_json, at)
            VALUES (?, ?, ?, ?, ?, ?)')->execute([$scheduleId, $this->actor, $action, json_encode($before, JSON_UNESCAPED_UNICODE),
                json_encode($after, JSON_UNESCAPED_UNICODE), $this->now]);
    }
}
