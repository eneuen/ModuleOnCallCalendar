<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Lib;

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
     * step 1, plus a person backup's ring time or a queue's wait, plus a
     * margin.
     *
     * @param array<string, mixed> $schedule
     */
    public function neededTimeout(array $schedule): int
    {
        $seconds = (int)$schedule['ring_seconds'];
        if ((string)$schedule['backup_type'] === 'member') {
            $seconds += (int)$schedule['backup_ring_seconds'];
        } elseif ((string)$schedule['backup_type'] === 'queue') {
            $stmt = $this->core->prepare('SELECT timeout_to_redirect_to_extension FROM m_CallQueues WHERE extension = ?');
            $stmt->execute([(string)$schedule['backup_number']]);
            $wait = (int)$stmt->fetchColumn();
            $seconds += $wait > 0 ? $wait : self::QUEUE_DEFAULT_WAIT;
        }

        return $seconds + self::TIMEOUT_MARGIN;
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
        $timeout = max((int)$route['timeout'], $this->neededTimeout($schedule));
        $error = ($this->saveRoute)($routeId, $number, $timeout);
        if ($error !== null) {
            throw new EditorException('occ_ErrRouteSave', ['reason' => $error]);
        }
        if ($link === null) {
            $this->module->prepare('INSERT INTO ' . ScheduleRepository::ROUTE_LINKS . ' (schedule_id, route_id, previous_extension,
                previous_timeout, linked_extension, linked_at, linked_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([(int)$schedule['id'], $routeId, (string)$route['extension'], (int)$route['timeout'], $number, $this->now, $this->actor]);
        }
        $this->audit((int)$schedule['id'], 'route.link', ['route_id' => $routeId, 'extension' => $route['extension'], 'timeout' => (int)$route['timeout']],
            ['route_id' => $routeId, 'extension' => $number, 'timeout' => $timeout]);
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
        $route = $this->core->prepare('SELECT extension FROM m_IncomingRoutingTable WHERE id = ?');
        $route->execute([$routeId]);
        $current = $route->fetchColumn();
        $restored = false;
        if ($current !== false && (string)$current === (string)$link['linked_extension']) {
            $error = ($this->saveRoute)($routeId, (string)$link['previous_extension'], (int)$link['previous_timeout']);
            if ($error !== null) {
                throw new EditorException('occ_ErrRouteSave', ['reason' => $error]);
            }
            $restored = true;
        }
        $this->module->prepare('DELETE FROM ' . ScheduleRepository::ROUTE_LINKS . ' WHERE route_id = ?')->execute([$routeId]);
        $this->audit($scheduleId, 'route.unlink', ['route_id' => $routeId, 'extension' => $current === false ? null : (string)$current],
            ['route_id' => $routeId, 'extension' => $restored ? (string)$link['previous_extension'] : null, 'restored' => $restored]);

        return $restored;
    }

    /**
     * Unlinks every route of a schedule (before deleting it, or before the
     * module is disabled).
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
     * Everything in the core that sends calls to a schedule's number:
     * incoming routes (linked or set by hand), off-work time rules, IVR menu
     * options and timeouts, queue redirects and employees' forwarding.
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
            'queue'      => 'SELECT id, name FROM m_CallQueues WHERE timeout_extension = ? OR redirect_to_extension_if_empty = ?',
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
