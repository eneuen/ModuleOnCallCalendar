<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Lib;

use PDO;

/**
 * Reads schedules from the module's database, and builds each schedule's
 * plan in the shape DutyResolver takes.
 */
final class ScheduleRepository
{
    public const string SCHEDULES = 'm_Schedules';
    public const string MEMBERS = 'm_Members';
    public const string SHIFT_TYPES = 'm_ShiftTypes';
    public const string ROSTERS = 'm_Rosters';
    public const string ROSTER_CELLS = 'm_RosterCells';
    public const string ROTATIONS = 'm_Rotations';
    public const string OVERRIDES = 'm_Overrides';
    public const string AUDIT = 'm_Audit';
    public const string ROUTE_LINKS = 'm_RouteLinks';

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array<string, mixed>> every schedule, by name */
    public function schedules(): array
    {
        return array_map(
            self::castSchedule(...),
            $this->db->query('SELECT * FROM ' . self::SCHEDULES . ' ORDER BY name COLLATE NOCASE, id')->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /** @return array<string, mixed>|null */
    public function schedule(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ' . self::SCHEDULES . ' WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::castSchedule($row);
    }

    /**
     * A schedule's members, with each employee's current name and extension
     * (null when the employee or their extension is gone).
     *
     * @param array<int, array{name: string, extension: string}> $directory Employees::directory()
     * @return list<array{id: int, user_id: int, colour: string, name: string, extension: ?string}>
     */
    public function members(int $scheduleId, array $directory): array
    {
        $members = [];
        foreach ($this->rows('SELECT * FROM ' . self::MEMBERS . ' WHERE schedule_id = ? ORDER BY id', [$scheduleId]) as $row) {
            $userId = (int)$row['user_id'];
            $members[] = [
                'id'        => (int)$row['id'],
                'user_id'   => $userId,
                'colour'    => (string)$row['colour'],
                'name'      => $directory[$userId]['name'] ?? '',
                'extension' => $directory[$userId]['extension'] ?? null,
            ];
        }

        return $members;
    }

    /** @return list<array{id: int, name: string, start: string, end: string, weekdays: list<int>, sort: int}> */
    public function shiftTypes(int $scheduleId): array
    {
        return array_map(static fn (array $r): array => [
            'id' => (int)$r['id'], 'name' => (string)$r['name'], 'start' => (string)$r['start_time'],
            'end' => (string)$r['end_time'], 'weekdays' => self::intList((string)$r['weekdays']), 'sort' => (int)$r['sort'],
        ], $this->rows('SELECT * FROM ' . self::SHIFT_TYPES . ' WHERE schedule_id = ? ORDER BY sort, id', [$scheduleId]));
    }

    /** @return list<array<string, mixed>> with their cells */
    public function rosters(int $scheduleId): array
    {
        $rosters = [];
        foreach ($this->rows('SELECT * FROM ' . self::ROSTERS . ' WHERE schedule_id = ? ORDER BY priority, id', [$scheduleId]) as $r) {
            $rosters[] = [
                'id' => (int)$r['id'], 'priority' => (int)$r['priority'], 'valid_from' => (string)$r['valid_from'],
                'valid_until' => (string)$r['valid_until'], 'cycle_weeks' => (int)$r['cycle_weeks'],
                ...self::ruleBackup($r),
                'cells' => array_map(static fn (array $c): array => [
                    'shift_type_id' => (int)$c['shift_type_id'], 'week' => (int)$c['week'],
                    'weekday' => (int)$c['weekday'], 'member_id' => (int)$c['member_id'],
                ], $this->rows('SELECT * FROM ' . self::ROSTER_CELLS . ' WHERE roster_id = ? ORDER BY id', [(int)$r['id']])),
            ];
        }

        return $rosters;
    }

    /** @return list<array<string, mixed>> */
    public function rotations(int $scheduleId): array
    {
        return array_map(static fn (array $r): array => [
            'id' => (int)$r['id'], 'priority' => (int)$r['priority'], 'members' => self::intList((string)$r['members']),
            'first_handover' => (string)$r['first_handover'], 'turn_days' => (int)$r['turn_days'],
            'active_hours' => self::activeHours((string)$r['active_hours']), 'valid_until' => (string)$r['valid_until'],
            ...self::ruleBackup($r),
        ], $this->rows('SELECT * FROM ' . self::ROTATIONS . ' WHERE schedule_id = ? ORDER BY priority, id', [$scheduleId]));
    }

    /**
     * Overrides that end after $sinceLocal ("Y-m-d H:i"; all when empty).
     *
     * @return list<array<string, mixed>>
     */
    public function overrides(int $scheduleId, string $sinceLocal = ''): array
    {
        $rows = $sinceLocal === ''
            ? $this->rows('SELECT * FROM ' . self::OVERRIDES . ' WHERE schedule_id = ? ORDER BY start_at, id', [$scheduleId])
            : $this->rows('SELECT * FROM ' . self::OVERRIDES . ' WHERE schedule_id = ? AND end_at > ? ORDER BY start_at, id', [$scheduleId, $sinceLocal]);

        return array_map(static fn (array $r): array => [
            'id' => (int)$r['id'], 'kind' => (string)$r['kind'], 'start_at' => (string)$r['start_at'],
            'end_at' => (string)$r['end_at'], 'members' => self::intList((string)$r['members']),
            'destination' => (string)$r['destination'], 'note' => (string)$r['note'],
            'swap_group' => (int)$r['swap_group'], 'created_by' => (string)$r['created_by'], 'created_at' => (int)$r['created_at'],
        ], $rows);
    }

    /**
     * Everything DutyResolver needs for one schedule.
     *
     * @param array<int, array{name: string, extension: string}> $directory Employees::directory()
     * @param string $sinceLocal leave out overrides that ended before this ("Y-m-d H:i")
     * @return array<string, mixed>
     */
    public function plan(int $scheduleId, array $directory, string $sinceLocal = ''): array
    {
        $members = [];
        foreach ($this->members($scheduleId, $directory) as $m) {
            $members[$m['id']] = ['name' => $m['name'], 'extension' => $m['extension']];
        }

        $withBackup = static fn (array $rule): array => $rule + ['backup' => BackupSpec::of($rule, $directory)];

        return [
            'members'     => $members,
            'shift_types' => $this->shiftTypes($scheduleId),
            'rosters'     => array_map($withBackup, $this->rosters($scheduleId)),
            'rotations'   => array_map($withBackup, $this->rotations($scheduleId)),
            'overrides'   => $this->overrides($scheduleId, $sinceLocal),
        ];
    }

    /**
     * @param list<scalar> $params
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function castSchedule(array $row): array
    {
        foreach (['id', 'ring_seconds', 'backup_ring_seconds'] as $key) {
            $row[$key] = (int)$row[$key];
        }
        $backupUser = $row['backup_user_id'] ?? null;
        $row['backup_user_id'] = $backupUser === null || $backupUser === '' ? null : (int)$backupUser;
        $greeting = $row['greeting_sound_id'] ?? null;
        $row['greeting_sound_id'] = $greeting === null || $greeting === '' ? null : (int)$greeting;
        foreach (['backup_type', 'backup_number', 'outside_number', 'template', 'callerid_prefix'] as $key) {
            $row[$key] = (string)($row[$key] ?? '');
        }

        return $row;
    }

    /**
     * A roster's or rotation's own backup fields ('' type: the schedule's backup).
     *
     * @param array<string, mixed> $row
     * @return array{backup_type: string, backup_number: string, backup_user_id: ?int, backup_ring_seconds: int}
     */
    private static function ruleBackup(array $row): array
    {
        $user = $row['backup_user_id'] ?? null;

        return [
            'backup_type' => (string)($row['backup_type'] ?? ''),
            'backup_number' => (string)($row['backup_number'] ?? ''),
            'backup_user_id' => $user === null || $user === '' ? null : (int)$user,
            'backup_ring_seconds' => (int)($row['backup_ring_seconds'] ?? 20) ?: 20,
        ];
    }

    /** @return list<int> */
    public static function intList(string $csv): array
    {
        return array_values(array_map('intval', array_filter(explode(',', $csv), static fn (string $v): bool => ctype_digit(trim($v)))));
    }

    /** @return list<array{weekdays: list<int>, start: string, end: string}> */
    public static function activeHours(string $json): array
    {
        $windows = json_decode($json === '' ? '[]' : $json, true);
        if (!is_array($windows)) {
            return [];
        }
        $out = [];
        foreach ($windows as $w) {
            if (is_array($w) && isset($w['weekdays'], $w['start'], $w['end']) && is_array($w['weekdays'])) {
                $out[] = ['weekdays' => array_values(array_map('intval', $w['weekdays'])), 'start' => (string)$w['start'], 'end' => (string)$w['end']];
            }
        }

        return $out;
    }
}
