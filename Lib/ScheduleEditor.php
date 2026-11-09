<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Lib;

use PDO;
use Throwable;

/**
 * Every change to schedules goes through here: it validates, writes the
 * module's database in one transaction, and records the change in the audit
 * log. The module page and any later API use it, so the rules hold
 * whatever the client sends.
 *
 * Refusals are EditorException with a translation key. The core's own
 * objects (the schedule's extension, linked routes) are changed by the
 * caller, which also asks the worker to publish and reloads the dialplan
 * when needed; see the *_RELOAD comments.
 *
 * People are always MikoPBX employees (user ids). Picking someone for a
 * roster cell, rotation, override or backup makes them a member of the
 * schedule automatically.
 */
final class ScheduleEditor
{
    public const array TEMPLATES = ['shift', 'oncall', 'blank'];
    public const array BACKUP_TYPES = ['', 'member', 'queue'];

    private const int MIN_RING = 5;
    private const int MAX_RING = 300;

    /** Member colours, given out in turn. */
    private const array COLOURS = ['#2185d0', '#21ba45', '#f2711c', '#a333c8', '#00b5ad', '#e03997', '#fbbd08', '#6435c9', '#db2828', '#a5673f'];

    private readonly ScheduleRepository $repository;

    /**
     * @param PDO $module the module's database, writable
     * @param PDO $core the core's configuration database, read only
     * @param string $actor who makes the changes, for the audit log
     */
    public function __construct(
        private readonly PDO $module,
        private readonly PDO $core,
        private readonly string $actor,
        private readonly int $now,
    ) {
        $this->repository = new ScheduleRepository($module);
    }

    // ------------------------------------------------------------------ schedules

    /**
     * The first free number from 2200 up, for a new schedule.
     */
    public function suggestNumber(): string
    {
        $taken = array_map('strval', $this->core->query('SELECT number FROM m_Extensions')->fetchAll(PDO::FETCH_COLUMN));
        foreach (range(2200, 99999) as $n) {
            if (!in_array((string)$n, $taken, true)) {
                return (string)$n;
            }
        }

        return '';
    }

    /**
     * Creates a schedule from a template. The number must be free in the PBX;
     * the caller then registers it in the core (ScheduleNumbers::register) and
     * deletes the schedule again if that fails. DIALPLAN_RELOAD.
     *
     * $data: name, number, template, and optionally the chain fields of
     * updateSchedule(), shift_names (the template's two shift names in the
     * admin's language) and, for an on-call schedule, rotation (see
     * saveRotation()).
     *
     * @param array<string, mixed> $data
     * @return int the new schedule's id
     */
    public function createSchedule(array $data): int
    {
        $name = self::name($data['name'] ?? '');
        $number = (string)($data['number'] ?? '');
        $template = (string)($data['template'] ?? 'blank');
        if (preg_match('/^\d{2,8}$/', $number) !== 1) {
            throw new EditorException('occ_ErrNumberFormat');
        }
        if (!in_array($template, self::TEMPLATES, true)) {
            throw new EditorException('occ_ErrInvalid');
        }
        // Any number the PBX already has is taken, another module's included.
        if ($this->scheduleByNumber($number) !== null || $this->coreExtensionType($number) !== null) {
            throw new EditorException('occ_ErrNumberTaken', ['number' => $number]);
        }

        return $this->transaction(function () use ($data, $name, $number, $template): int {
            $defaults = Templates::schedule($template);
            $this->module->prepare('INSERT INTO ' . ScheduleRepository::SCHEDULES . ' (name, number, template, ring_seconds,
                backup_type, backup_number, backup_user_id, backup_ring_seconds, final_number, outside_number, enabled)
                VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?)')
                ->execute([$name, $number, $template, $defaults['ring_seconds'], '', '', $defaults['backup_ring_seconds'],
                    'voicemail', '', '1']);
            $id = (int)$this->module->lastInsertId();

            $sort = 0;
            $names = is_array($data['shift_names'] ?? null) ? array_values(array_map(self::name(...), $data['shift_names'])) : [];
            foreach (Templates::shiftTypes($template, $names) as $shift) {
                $this->insertShiftType($id, $shift['name'], $shift['start'], $shift['end'], $shift['weekdays'], ++$sort);
            }
            if ($template === 'shift') {
                $this->module->prepare('INSERT INTO ' . ScheduleRepository::ROSTERS . ' (schedule_id, priority, valid_from, valid_until,
                    cycle_weeks) VALUES (?, 1, ?, \'\', 1)')->execute([$id, Templates::weekStart($this->now, $this->timezoneName())]);
            }
            $this->applyChain($id, $data + ['ring_seconds' => $defaults['ring_seconds'], 'backup_ring_seconds' => $defaults['backup_ring_seconds']]);
            if ($template === 'oncall' && isset($data['rotation']) && is_array($data['rotation'])) {
                $this->writeRotation($id, null, $data['rotation']);
            }
            $this->audit($id, 'schedule.create', null, $this->repository->schedule($id));

            return $id;
        });
    }

    /**
     * Changes a schedule's name and if-no-answer chain. The number stays.
     * DIALPLAN_RELOAD (and the caller updates the extension's caller ID when
     * the name changed).
     *
     * @param array<string, mixed> $data name, ring_seconds, backup_type, backup_number, backup_user_id,
     *     backup_ring_seconds, final_number, outside_number, greeting_sound_id, enabled
     */
    public function updateSchedule(int $id, array $data): void
    {
        $before = $this->requireSchedule($id);
        $this->transaction(function () use ($id, $data, $before): void {
            if (array_key_exists('name', $data)) {
                $this->module->prepare('UPDATE ' . ScheduleRepository::SCHEDULES . ' SET name = ? WHERE id = ?')
                    ->execute([self::name($data['name']), $id]);
            }
            if (array_key_exists('enabled', $data)) {
                $this->module->prepare('UPDATE ' . ScheduleRepository::SCHEDULES . ' SET enabled = ? WHERE id = ?')
                    ->execute([$data['enabled'] ? '1' : '0', $id]);
            }
            if (array_key_exists('greeting_sound_id', $data)) {
                $sound = $data['greeting_sound_id'];
                $sound = $sound === null || $sound === '' || (int)$sound === 0 ? null : (int)$sound;
                if ($sound !== null && !$this->soundExists($sound)) {
                    throw new EditorException('occ_ErrSound');
                }
                $this->module->prepare('UPDATE ' . ScheduleRepository::SCHEDULES . ' SET greeting_sound_id = ? WHERE id = ?')
                    ->execute([$sound, $id]);
            }
            $this->applyChain($id, $data + $before, $before);
            $this->audit($id, 'schedule.update', $before, $this->repository->schedule($id));
        });
    }

    /**
     * Deletes a schedule and its plan. The caller checks first that nothing
     * routes to its number, and removes the number from the core afterwards
     * (the core refuses while the schedule exists). DIALPLAN_RELOAD.
     */
    public function deleteSchedule(int $id): void
    {
        $before = $this->requireSchedule($id);
        $this->transaction(function () use ($id, $before): void {
            $this->module->prepare('DELETE FROM ' . ScheduleRepository::ROSTER_CELLS . ' WHERE roster_id IN
                (SELECT id FROM ' . ScheduleRepository::ROSTERS . ' WHERE schedule_id = ?)')->execute([$id]);
            foreach ([ScheduleRepository::ROSTERS, ScheduleRepository::ROTATIONS, ScheduleRepository::OVERRIDES,
                ScheduleRepository::SHIFT_TYPES, ScheduleRepository::MEMBERS, ScheduleRepository::ROUTE_LINKS] as $table) {
                $this->module->prepare("DELETE FROM $table WHERE schedule_id = ?")->execute([$id]);
            }
            $this->module->prepare('DELETE FROM ' . ScheduleRepository::SCHEDULES . ' WHERE id = ?')->execute([$id]);
            $this->audit($id, 'schedule.delete', $before, null);
        });
    }

    /**
     * Validates and writes the chain fields. On an update ($before), only the
     * fields that change are checked against the PBX: a destination deleted
     * in the core since doesn't stop the admin from renaming or disabling the
     * schedule, or from choosing a new destination.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $before
     */
    private function applyChain(int $id, array $data, ?array $before = null): void
    {
        $changed = static fn (string $key): bool => $before === null || (string)($data[$key] ?? '') !== (string)($before[$key] ?? '');
        $ring = self::ringSeconds($data['ring_seconds'] ?? 20);
        $backupRing = self::ringSeconds($data['backup_ring_seconds'] ?? 20);
        $backupType = (string)($data['backup_type'] ?? '');
        if (!in_array($backupType, self::BACKUP_TYPES, true)) {
            throw new EditorException('occ_ErrInvalid');
        }
        $backupNumber = '';
        $backupUser = null;
        if ($backupType === 'queue') {
            $backupNumber = (string)($data['backup_number'] ?? '');
            if (($changed('backup_type') || $changed('backup_number')) && $this->coreExtensionType($backupNumber) !== 'QUEUE') {
                throw new EditorException('occ_ErrBackupQueue');
            }
        } elseif ($backupType === 'member') {
            $backupUser = (int)($data['backup_user_id'] ?? 0);
            if (($changed('backup_type') || $changed('backup_user_id')) && $this->extensionOf($backupUser) === null) {
                throw new EditorException('occ_ErrNoExtension');
            }
            $this->memberId($id, $backupUser);
        }
        $number = (string)$this->requireSchedule($id)['number'];
        $final = (string)($data['final_number'] ?? 'voicemail');
        $outside = (string)($data['outside_number'] ?? '');
        if ($changed('final_number')) {
            $this->destination($final, $number, false);
        }
        if ($changed('outside_number')) {
            $this->destination($outside, $number, true);
        }

        $this->module->prepare('UPDATE ' . ScheduleRepository::SCHEDULES . ' SET ring_seconds = ?, backup_type = ?,
            backup_number = ?, backup_user_id = ?, backup_ring_seconds = ?, final_number = ?, outside_number = ? WHERE id = ?')
            ->execute([$ring, $backupType, $backupNumber, $backupUser, $backupRing, $final, $outside, $id]);
    }

    // ------------------------------------------------------------------ shift types and rosters

    /**
     * Creates ($data without id) or changes a shift type. PUBLISH.
     *
     * @param array<string, mixed> $data name, start, end, weekdays (list of 1-7)
     * @return int the shift type's id
     */
    public function saveShiftType(int $scheduleId, array $data): int
    {
        $this->requireSchedule($scheduleId);
        $name = self::name($data['name'] ?? '');
        $start = self::time($data['start'] ?? '');
        $end = self::time($data['end'] ?? '');
        $weekdays = self::weekdays($data['weekdays'] ?? []);
        $id = (int)($data['id'] ?? 0);

        return $this->transaction(function () use ($scheduleId, $id, $name, $start, $end, $weekdays): int {
            if ($id === 0) {
                $sort = (int)$this->value('SELECT COALESCE(MAX(sort), 0) FROM ' . ScheduleRepository::SHIFT_TYPES . ' WHERE schedule_id = ?', [$scheduleId]);
                $id = $this->insertShiftType($scheduleId, $name, $start, $end, $weekdays, $sort + 1);
                $this->audit($scheduleId, 'shift_type.create', null, $this->row(ScheduleRepository::SHIFT_TYPES, $id));

                return $id;
            }
            $before = $this->requireRow(ScheduleRepository::SHIFT_TYPES, $id, $scheduleId);
            $this->module->prepare('UPDATE ' . ScheduleRepository::SHIFT_TYPES . ' SET name = ?, start_time = ?, end_time = ?,
                weekdays = ? WHERE id = ?')->execute([$name, $start, $end, implode(',', $weekdays), $id]);
            // Cells on weekdays the shift no longer runs could neither ring nor be cleared.
            $this->module->prepare('DELETE FROM ' . ScheduleRepository::ROSTER_CELLS . ' WHERE shift_type_id = ? AND weekday NOT IN ('
                . implode(',', $weekdays) . ')')->execute([$id]);
            $this->audit($scheduleId, 'shift_type.update', $before, $this->row(ScheduleRepository::SHIFT_TYPES, $id));

            return $id;
        });
    }

    /** Deletes a shift type and its roster cells. PUBLISH. */
    public function deleteShiftType(int $scheduleId, int $id): void
    {
        $before = $this->requireRow(ScheduleRepository::SHIFT_TYPES, $id, $scheduleId);
        $this->transaction(function () use ($scheduleId, $id, $before): void {
            $this->module->prepare('DELETE FROM ' . ScheduleRepository::ROSTER_CELLS . ' WHERE shift_type_id = ?')->execute([$id]);
            $this->module->prepare('DELETE FROM ' . ScheduleRepository::SHIFT_TYPES . ' WHERE id = ?')->execute([$id]);
            $this->audit($scheduleId, 'shift_type.delete', $before, null);
        });
    }

    /**
     * Puts a schedule's shift types in this order (all of them). Lower in the
     * list wins where they overlap. PUBLISH.
     *
     * @param list<int> $ids
     */
    public function orderShiftTypes(int $scheduleId, array $ids): void
    {
        $this->requireSchedule($scheduleId);
        $current = array_column($this->repository->shiftTypes($scheduleId), 'id');
        $ids = array_map('intval', $ids);
        if (count($ids) !== count($current) || array_diff($current, $ids) !== []) {
            throw new EditorException('occ_ErrInvalid');
        }
        $this->transaction(function () use ($scheduleId, $ids, $current): void {
            foreach ($ids as $sort => $id) {
                $this->module->prepare('UPDATE ' . ScheduleRepository::SHIFT_TYPES . ' SET sort = ? WHERE id = ?')->execute([$sort + 1, $id]);
            }
            $this->audit($scheduleId, 'shift_type.order', ['order' => $current], ['order' => $ids]);
        });
    }

    /**
     * Creates ($data without id) or changes a roster's dates and cycle. A
     * shorter cycle drops the cells of the weeks it no longer has. PUBLISH.
     *
     * @param array<string, mixed> $data valid_from, valid_until ('' = no end), cycle_weeks (1-4)
     */
    public function saveRoster(int $scheduleId, array $data): int
    {
        $this->requireSchedule($scheduleId);
        $from = self::date($data['valid_from'] ?? '');
        $until = (string)($data['valid_until'] ?? '') === '' ? '' : self::date($data['valid_until']);
        if ($until !== '' && $until < $from) {
            throw new EditorException('occ_ErrDateOrder');
        }
        $cycle = (int)($data['cycle_weeks'] ?? 1);
        if ($cycle < 1 || $cycle > 4) {
            throw new EditorException('occ_ErrInvalid');
        }
        $id = (int)($data['id'] ?? 0);

        return $this->transaction(function () use ($scheduleId, $id, $from, $until, $cycle): int {
            if ($id === 0) {
                $this->module->prepare('INSERT INTO ' . ScheduleRepository::ROSTERS . ' (schedule_id, priority, valid_from,
                    valid_until, cycle_weeks) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$scheduleId, $this->nextPriority($scheduleId), $from, $until, $cycle]);
                $id = (int)$this->module->lastInsertId();
                $this->audit($scheduleId, 'roster.create', null, $this->row(ScheduleRepository::ROSTERS, $id));

                return $id;
            }
            $before = $this->requireRow(ScheduleRepository::ROSTERS, $id, $scheduleId);
            $this->module->prepare('UPDATE ' . ScheduleRepository::ROSTERS . ' SET valid_from = ?, valid_until = ?, cycle_weeks = ?
                WHERE id = ?')->execute([$from, $until, $cycle, $id]);
            $this->module->prepare('DELETE FROM ' . ScheduleRepository::ROSTER_CELLS . ' WHERE roster_id = ? AND week > ?')->execute([$id, $cycle]);
            $this->audit($scheduleId, 'roster.update', $before, $this->row(ScheduleRepository::ROSTERS, $id));

            return $id;
        });
    }

    /** Deletes a roster and its cells. PUBLISH. */
    public function deleteRoster(int $scheduleId, int $id): void
    {
        $before = $this->requireRow(ScheduleRepository::ROSTERS, $id, $scheduleId);
        $this->transaction(function () use ($scheduleId, $id, $before): void {
            $this->module->prepare('DELETE FROM ' . ScheduleRepository::ROSTER_CELLS . ' WHERE roster_id = ?')->execute([$id]);
            $this->module->prepare('DELETE FROM ' . ScheduleRepository::ROSTERS . ' WHERE id = ?')->execute([$id]);
            $this->audit($scheduleId, 'roster.delete', $before, null);
        });
    }

    /**
     * Sets who works one roster cell (a shift type on a weekday of a cycle
     * week); an empty list empties it. Everyone listed rings together.
     * PUBLISH.
     *
     * @param list<int> $userIds employees
     */
    public function setCell(int $scheduleId, int $rosterId, int $shiftTypeId, int $week, int $weekday, array $userIds): void
    {
        $roster = $this->requireRow(ScheduleRepository::ROSTERS, $rosterId, $scheduleId);
        $shift = $this->requireRow(ScheduleRepository::SHIFT_TYPES, $shiftTypeId, $scheduleId);
        if ($week < 1 || $week > (int)$roster['cycle_weeks'] || $weekday < 1 || $weekday > 7
            || !in_array($weekday, ScheduleRepository::intList((string)$shift['weekdays']), true)) {
            throw new EditorException('occ_ErrInvalid');
        }
        $userIds = $this->employees($userIds);
        $this->transaction(function () use ($scheduleId, $rosterId, $shiftTypeId, $week, $weekday, $userIds): void {
            $before = $this->cellUsers($rosterId, $shiftTypeId, $week, $weekday);
            $this->module->prepare('DELETE FROM ' . ScheduleRepository::ROSTER_CELLS . ' WHERE roster_id = ? AND shift_type_id = ?
                AND week = ? AND weekday = ?')->execute([$rosterId, $shiftTypeId, $week, $weekday]);
            foreach ($userIds as $userId) {
                $this->module->prepare('INSERT INTO ' . ScheduleRepository::ROSTER_CELLS . ' (roster_id, shift_type_id, week, weekday,
                    member_id) VALUES (?, ?, ?, ?, ?)')->execute([$rosterId, $shiftTypeId, $week, $weekday, $this->memberId($scheduleId, $userId)]);
            }
            $cell = ['roster_id' => $rosterId, 'shift_type_id' => $shiftTypeId, 'week' => $week, 'weekday' => $weekday];
            $this->audit($scheduleId, 'roster.cell', $cell + ['user_ids' => $before], $cell + ['user_ids' => $userIds]);
        });
    }

    // ------------------------------------------------------------------ rotations

    /**
     * Creates ($data without id) or changes a rotation. PUBLISH.
     *
     * @param array<string, mixed> $data user_ids (in turn order), first_handover ("Y-m-d H:i"),
     *     turn_days (1-60), active_hours (list of {weekdays, start, end}; empty = always), valid_until
     */
    public function saveRotation(int $scheduleId, array $data): int
    {
        $this->requireSchedule($scheduleId);
        $id = (int)($data['id'] ?? 0);

        return $this->transaction(function () use ($scheduleId, $id, $data): int {
            $before = $id === 0 ? null : $this->requireRow(ScheduleRepository::ROTATIONS, $id, $scheduleId);
            $id = $this->writeRotation($scheduleId, $id === 0 ? null : $id, $data);
            $this->audit($scheduleId, $before === null ? 'rotation.create' : 'rotation.update', $before, $this->row(ScheduleRepository::ROTATIONS, $id));

            return $id;
        });
    }

    /** Deletes a rotation. PUBLISH. */
    public function deleteRotation(int $scheduleId, int $id): void
    {
        $before = $this->requireRow(ScheduleRepository::ROTATIONS, $id, $scheduleId);
        $this->transaction(function () use ($scheduleId, $id, $before): void {
            $this->module->prepare('DELETE FROM ' . ScheduleRepository::ROTATIONS . ' WHERE id = ?')->execute([$id]);
            $this->audit($scheduleId, 'rotation.delete', $before, null);
        });
    }

    /**
     * Sets which rule wins where a schedule's rosters and rotations overlap:
     * the first in the list. PUBLISH.
     *
     * @param list<array<string, mixed>> $rules {type, id} for every roster and rotation of the schedule
     */
    public function orderRules(int $scheduleId, array $rules): void
    {
        $this->requireSchedule($scheduleId);
        $current = [];
        foreach ($this->repository->rosters($scheduleId) as $r) {
            $current[] = 'roster:' . $r['id'];
        }
        foreach ($this->repository->rotations($scheduleId) as $r) {
            $current[] = 'rotation:' . $r['id'];
        }
        $wanted = array_map(static fn (array $r): string => (string)($r['type'] ?? '') . ':' . (int)($r['id'] ?? 0), $rules);
        if (count($wanted) !== count($current) || array_diff($current, $wanted) !== [] || count(array_unique($wanted)) !== count($wanted)) {
            throw new EditorException('occ_ErrInvalid');
        }
        $this->transaction(function () use ($scheduleId, $wanted, $current): void {
            foreach ($wanted as $priority => $key) {
                [$type, $id] = explode(':', $key);
                $table = $type === 'roster' ? ScheduleRepository::ROSTERS : ScheduleRepository::ROTATIONS;
                $this->module->prepare("UPDATE $table SET priority = ? WHERE id = ?")->execute([$priority + 1, (int)$id]);
            }
            $this->audit($scheduleId, 'rules.order', ['order' => $current], ['order' => $wanted]);
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeRotation(int $scheduleId, ?int $id, array $data): int
    {
        $userIds = $this->employees($data['user_ids'] ?? []);
        if ($userIds === []) {
            throw new EditorException('occ_ErrRotationPeople');
        }
        $first = self::localDateTime($data['first_handover'] ?? '');
        $turnDays = (int)($data['turn_days'] ?? 7);
        if ($turnDays < 1 || $turnDays > 60) {
            throw new EditorException('occ_ErrTurnDays');
        }
        $windows = [];
        foreach (is_array($data['active_hours'] ?? null) ? $data['active_hours'] : [] as $w) {
            if (!is_array($w)) {
                throw new EditorException('occ_ErrInvalid');
            }
            $windows[] = ['weekdays' => self::weekdays($w['weekdays'] ?? []), 'start' => self::time($w['start'] ?? ''), 'end' => self::time($w['end'] ?? '')];
        }
        $until = (string)($data['valid_until'] ?? '') === '' ? '' : self::date($data['valid_until']);
        if ($until !== '' && $until < substr($first, 0, 10)) {
            throw new EditorException('occ_ErrDateOrder');
        }
        $members = implode(',', array_map(fn (int $u): int => $this->memberId($scheduleId, $u), $userIds));
        $hours = json_encode($windows);

        if ($id === null) {
            $this->module->prepare('INSERT INTO ' . ScheduleRepository::ROTATIONS . ' (schedule_id, priority, members, first_handover,
                turn_days, active_hours, valid_until) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$scheduleId, $this->nextPriority($scheduleId), $members, $first, $turnDays, $hours, $until]);

            return (int)$this->module->lastInsertId();
        }
        $this->module->prepare('UPDATE ' . ScheduleRepository::ROTATIONS . ' SET members = ?, first_handover = ?, turn_days = ?,
            active_hours = ?, valid_until = ? WHERE id = ?')->execute([$members, $first, $turnDays, $hours, $until, $id]);

        return $id;
    }

    // ------------------------------------------------------------------ overrides

    /**
     * Adds an override. PUBLISH.
     *
     * @param array<string, mixed> $data kind (cover, takeover, elsewhere), start_at, end_at ("Y-m-d H:i"),
     *     user_ids (cover, takeover), destination (elsewhere: one of the schedule's own destinations), note
     * @return int the override's id
     */
    public function addOverride(int $scheduleId, array $data): int
    {
        $schedule = $this->requireSchedule($scheduleId);
        $row = $this->overrideRow($schedule, $data);

        return $this->transaction(function () use ($scheduleId, $row): int {
            $id = $this->insertOverride($scheduleId, $row, null);
            $this->audit($scheduleId, 'override.create', null, $this->row(ScheduleRepository::OVERRIDES, $id));

            return $id;
        });
    }

    /**
     * Swaps two shifts between two people: $userA takes the time range B and
     * $userB the range A. Stored as two cover overrides that are deleted
     * together. PUBLISH.
     *
     * @param array<string, mixed> $data user_a, start_a, end_a, user_b, start_b, end_b, note
     * @return list<int> the two overrides' ids
     */
    public function addSwap(int $scheduleId, array $data): array
    {
        $schedule = $this->requireSchedule($scheduleId);
        $note = self::note($data['note'] ?? '');
        $a = $this->overrideRow($schedule, ['kind' => 'swap', 'start_at' => $data['start_a'] ?? '', 'end_at' => $data['end_a'] ?? '',
            'user_ids' => [(int)($data['user_b'] ?? 0)], 'note' => $note]);
        $b = $this->overrideRow($schedule, ['kind' => 'swap', 'start_at' => $data['start_b'] ?? '', 'end_at' => $data['end_b'] ?? '',
            'user_ids' => [(int)($data['user_a'] ?? 0)], 'note' => $note]);
        if ($a['members'] === $b['members']) {
            throw new EditorException('occ_ErrSwapSamePerson');
        }
        if ($a['start_at'] < $b['end_at'] && $b['start_at'] < $a['end_at']) {
            // Overlapping shifts can't be exchanged: the newer half would undo the older.
            throw new EditorException('occ_ErrSwapOverlap');
        }

        return $this->transaction(function () use ($scheduleId, $a, $b): array {
            $first = $this->insertOverride($scheduleId, $a, null);
            $this->module->prepare('UPDATE ' . ScheduleRepository::OVERRIDES . ' SET swap_group = ? WHERE id = ?')->execute([$first, $first]);
            $second = $this->insertOverride($scheduleId, $b, $first);
            $this->audit($scheduleId, 'override.swap', null, [
                $this->row(ScheduleRepository::OVERRIDES, $first), $this->row(ScheduleRepository::OVERRIDES, $second),
            ]);

            return [$first, $second];
        });
    }

    /**
     * Moves, resizes or changes an override. Its kind stays. PUBLISH.
     *
     * @param array<string, mixed> $data the fields of addOverride(), any subset
     */
    public function updateOverride(int $scheduleId, int $id, array $data): void
    {
        $schedule = $this->requireSchedule($scheduleId);
        $before = $this->requireRow(ScheduleRepository::OVERRIDES, $id, $scheduleId);
        $current = [
            'kind' => (string)$before['kind'], 'start_at' => (string)$before['start_at'], 'end_at' => (string)$before['end_at'],
            'destination' => (string)$before['destination'], 'note' => (string)$before['note'],
        ];
        // Moving or resizing keeps the people as they are, even someone whose extension is gone since.
        $keepMembers = array_key_exists('user_ids', $data) ? null : (string)$before['members'];
        $row = $this->overrideRow($schedule, ['kind' => $current['kind']] + $data + $current, $keepMembers);
        $this->transaction(function () use ($scheduleId, $id, $row, $before): void {
            $this->module->prepare('UPDATE ' . ScheduleRepository::OVERRIDES . ' SET start_at = ?, end_at = ?, members = ?,
                destination = ?, note = ? WHERE id = ?')
                ->execute([$row['start_at'], $row['end_at'], $row['members'], $row['destination'], $row['note'], $id]);
            $this->audit($scheduleId, 'override.update', $before, $this->row(ScheduleRepository::OVERRIDES, $id));
        });
    }

    /** Deletes an override, and the other half of a swap. PUBLISH. */
    public function deleteOverride(int $scheduleId, int $id): void
    {
        $before = $this->requireRow(ScheduleRepository::OVERRIDES, $id, $scheduleId);
        $this->transaction(function () use ($scheduleId, $id, $before): void {
            $group = (int)$before['swap_group'];
            if ($group > 0) {
                $stmt = $this->module->prepare('SELECT * FROM ' . ScheduleRepository::OVERRIDES . ' WHERE schedule_id = ? AND swap_group = ? ORDER BY id');
                $stmt->execute([$scheduleId, $group]);
                $before = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $this->module->prepare('DELETE FROM ' . ScheduleRepository::OVERRIDES . ' WHERE schedule_id = ? AND swap_group = ?')
                    ->execute([$scheduleId, $group]);
            } else {
                $this->module->prepare('DELETE FROM ' . ScheduleRepository::OVERRIDES . ' WHERE id = ?')->execute([$id]);
            }
            $this->audit($scheduleId, 'override.delete', $before, null);
        });
    }

    /**
     * Validated override fields.
     *
     * @param array<string, mixed> $schedule
     * @param array<string, mixed> $data
     * @param string|null $keepMembers the stored members, kept as they are instead of validating user_ids
     * @return array{kind: string, start_at: string, end_at: string, members: string, destination: string, note: string}
     */
    private function overrideRow(array $schedule, array $data, ?string $keepMembers = null): array
    {
        $kind = (string)($data['kind'] ?? '');
        if (!in_array($kind, [DutyResolver::KIND_COVER, DutyResolver::KIND_SWAP, DutyResolver::KIND_TAKEOVER, DutyResolver::KIND_ELSEWHERE], true)) {
            throw new EditorException('occ_ErrInvalid');
        }
        $start = self::localDateTime($data['start_at'] ?? '');
        $end = self::localDateTime($data['end_at'] ?? '');
        if ($end <= $start) {
            throw new EditorException('occ_ErrTimeOrder');
        }
        $scheduleId = (int)$schedule['id'];
        $members = '';
        $destination = '';
        if ($kind === DutyResolver::KIND_ELSEWHERE) {
            $destination = (string)($data['destination'] ?? '');
            if (!in_array($destination, self::scheduleDestinations($schedule), true)) {
                throw new EditorException('occ_ErrElsewhere');
            }
        } elseif ($keepMembers !== null) {
            $members = $keepMembers;
        } else {
            $userIds = $this->employees($data['user_ids'] ?? []);
            if ($userIds === []) {
                throw new EditorException('occ_ErrPickPerson');
            }
            $members = implode(',', array_map(fn (int $u): int => $this->memberId($scheduleId, $u), $userIds));
        }

        return ['kind' => $kind, 'start_at' => $start, 'end_at' => $end, 'members' => $members, 'destination' => $destination,
            'note' => self::note($data['note'] ?? '')];
    }

    /**
     * Where a "send elsewhere" override may send calls: only the
     * destinations an admin already set for the schedule, so someone who may
     * edit duty can't route calls anywhere new.
     *
     * @param array<string, mixed> $schedule
     * @return list<string>
     */
    public static function scheduleDestinations(array $schedule): array
    {
        $list = [(string)$schedule['final_number']];
        if ((string)$schedule['outside_number'] !== '') {
            $list[] = (string)$schedule['outside_number'];
        }
        if ((string)$schedule['backup_type'] === 'queue' && (string)$schedule['backup_number'] !== '') {
            $list[] = (string)$schedule['backup_number'];
        }

        return array_values(array_unique($list));
    }

    /**
     * @param array{kind: string, start_at: string, end_at: string, members: string, destination: string, note: string} $row
     */
    private function insertOverride(int $scheduleId, array $row, ?int $swapGroup): int
    {
        $this->module->prepare('INSERT INTO ' . ScheduleRepository::OVERRIDES . ' (schedule_id, kind, start_at, end_at, members,
            destination, note, swap_group, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$scheduleId, $row['kind'], $row['start_at'], $row['end_at'], $row['members'], $row['destination'],
                $row['note'], $swapGroup, $this->actor, $this->now]);

        return (int)$this->module->lastInsertId();
    }

    // ------------------------------------------------------------------ members

    /**
     * Removes a member who is no longer used anywhere in the schedule.
     * PUBLISH.
     */
    public function removeMember(int $scheduleId, int $memberId): void
    {
        $before = $this->requireRow(ScheduleRepository::MEMBERS, $memberId, $scheduleId);
        if ($this->memberInUse($scheduleId, $memberId)) {
            throw new EditorException('occ_ErrMemberInUse');
        }
        $this->transaction(function () use ($scheduleId, $memberId, $before): void {
            $this->module->prepare('DELETE FROM ' . ScheduleRepository::MEMBERS . ' WHERE id = ?')->execute([$memberId]);
            $this->audit($scheduleId, 'member.delete', $before, null);
        });
    }

    /** The member id for an employee in a schedule, adding them if needed. */
    private function memberId(int $scheduleId, int $userId): int
    {
        $id = $this->value('SELECT id FROM ' . ScheduleRepository::MEMBERS . ' WHERE schedule_id = ? AND user_id = ?', [$scheduleId, $userId]);
        if ($id !== null) {
            return (int)$id;
        }
        $count = (int)$this->value('SELECT COUNT(*) FROM ' . ScheduleRepository::MEMBERS . ' WHERE schedule_id = ?', [$scheduleId]);
        $this->module->prepare('INSERT INTO ' . ScheduleRepository::MEMBERS . ' (schedule_id, user_id, colour) VALUES (?, ?, ?)')
            ->execute([$scheduleId, $userId, self::COLOURS[$count % count(self::COLOURS)]]);

        return (int)$this->module->lastInsertId();
    }

    private function memberInUse(int $scheduleId, int $memberId): bool
    {
        $schedule = $this->requireSchedule($scheduleId);
        $userId = (int)$this->value('SELECT user_id FROM ' . ScheduleRepository::MEMBERS . ' WHERE id = ?', [$memberId]);
        if ((string)$schedule['backup_type'] === 'member' && (int)$schedule['backup_user_id'] === $userId) {
            return true;
        }
        if ($this->value('SELECT 1 FROM ' . ScheduleRepository::ROSTER_CELLS . ' WHERE member_id = ? LIMIT 1', [$memberId]) !== null) {
            return true;
        }
        foreach ($this->repository->rotations($scheduleId) as $rotation) {
            if (in_array($memberId, $rotation['members'], true)) {
                return true;
            }
        }
        foreach ($this->repository->overrides($scheduleId) as $override) {
            if (in_array($memberId, $override['members'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Employees as a validated, de-duplicated list. Each must still exist and
     * have an internal extension.
     *
     * @return list<int>
     */
    private function employees(mixed $userIds): array
    {
        if (!is_array($userIds)) {
            throw new EditorException('occ_ErrInvalid');
        }
        $out = [];
        foreach ($userIds as $userId) {
            $userId = (int)$userId;
            if ($this->extensionOf($userId) === null) {
                throw new EditorException('occ_ErrNoExtension');
            }
            if (!in_array($userId, $out, true)) {
                $out[] = $userId;
            }
        }
        if (count($out) > 10) {
            throw new EditorException('occ_ErrTooManyPeople');
        }

        return $out;
    }

    /** @return list<int> */
    private function cellUsers(int $rosterId, int $shiftTypeId, int $week, int $weekday): array
    {
        $stmt = $this->module->prepare('SELECT m.user_id FROM ' . ScheduleRepository::ROSTER_CELLS . ' c JOIN ' . ScheduleRepository::MEMBERS
            . ' m ON m.id = c.member_id WHERE c.roster_id = ? AND c.shift_type_id = ? AND c.week = ? AND c.weekday = ? ORDER BY c.id');
        $stmt->execute([$rosterId, $shiftTypeId, $week, $weekday]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    // ------------------------------------------------------------------ core lookups

    private function extensionOf(int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }
        $stmt = $this->core->prepare("SELECT number FROM m_Extensions WHERE userid = ? AND type = 'SIP' ORDER BY number LIMIT 1");
        $stmt->execute([$userId]);
        $number = $stmt->fetchColumn();

        return $number === false ? null : (string)$number;
    }

    private function coreExtensionType(string $number): ?string
    {
        $stmt = $this->core->prepare('SELECT type FROM m_Extensions WHERE number = ? LIMIT 1');
        $stmt->execute([$number]);
        $type = $stmt->fetchColumn();

        return $type === false ? null : (string)$type;
    }

    private function soundExists(int $id): bool
    {
        try {
            $stmt = $this->core->prepare('SELECT 1 FROM m_SoundFiles WHERE id = ?');
            $stmt->execute([$id]);

            return $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * A destination the chain may jump to: any existing number in the core
     * that answers calls, except the schedule's own number.
     */
    private function destination(string $number, string $ownNumber, bool $optional): string
    {
        if ($optional && $number === '') {
            return '';
        }
        $type = $this->coreExtensionType($number);
        $allowed = ['SIP', 'QUEUE', 'IVR MENU', 'CONFERENCE', 'DIALPLAN APPLICATION', 'SYSTEM', 'MODULES'];
        if ($number === $ownNumber || $type === null || !in_array($type, $allowed, true) || !DutyPublisher::isDestination($number)) {
            throw new EditorException('occ_ErrDestination', ['number' => $number]);
        }
        if ($this->leadsBackTo($number, $ownNumber)) {
            throw new EditorException('occ_ErrDestinationCircle', ['number' => $number]);
        }

        return $number;
    }

    /**
     * Whether calls sent to $number can come back to $ownNumber through this
     * module's schedules (their final and outside-hours destinations), which
     * would send them round in a circle. The dialplan has a hop limit as a
     * backstop; this stops it being configured in the first place.
     */
    private function leadsBackTo(string $number, string $ownNumber): bool
    {
        $next = [];
        foreach ($this->module->query('SELECT number, final_number, outside_number FROM ' . ScheduleRepository::SCHEDULES)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $next[(string)$row['number']] = array_filter([(string)$row['final_number'], (string)$row['outside_number']]);
        }
        $seen = [];
        $queue = [$number];
        while ($queue !== []) {
            $current = array_shift($queue);
            if ($current === $ownNumber) {
                return true;
            }
            if (isset($seen[$current]) || !isset($next[$current])) {
                continue;
            }
            $seen[$current] = true;
            array_push($queue, ...$next[$current]);
        }

        return false;
    }

    private function timezoneName(): string
    {
        return ModuleContext::timezone($this->core)->getName();
    }

    // ------------------------------------------------------------------ plumbing

    /** @return array<string, mixed> */
    private function requireSchedule(int $id): array
    {
        return $this->repository->schedule($id) ?? throw new EditorException('occ_ErrNotFound');
    }

    /** @return array<string, mixed>|null */
    private function scheduleByNumber(string $number): ?array
    {
        $id = $this->value('SELECT id FROM ' . ScheduleRepository::SCHEDULES . ' WHERE number = ?', [$number]);

        return $id === null ? null : $this->repository->schedule((int)$id);
    }

    /** @return array<string, mixed> a row of $table that belongs to the schedule */
    private function requireRow(string $table, int $id, int $scheduleId): array
    {
        $row = $this->row($table, $id);
        $owner = $table === ScheduleRepository::ROSTER_CELLS ? null : (int)($row['schedule_id'] ?? 0);
        if ($row === null || $owner !== $scheduleId) {
            throw new EditorException('occ_ErrNotFound');
        }

        return $row;
    }

    /** @return array<string, mixed>|null */
    private function row(string $table, int $id): ?array
    {
        $stmt = $this->module->prepare("SELECT * FROM $table WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @param list<scalar> $params */
    private function value(string $sql, array $params): mixed
    {
        $stmt = $this->module->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();

        return $value === false ? null : $value;
    }

    private function nextPriority(int $scheduleId): int
    {
        $max = 0;
        foreach ([ScheduleRepository::ROSTERS, ScheduleRepository::ROTATIONS] as $table) {
            $max = max($max, (int)$this->value("SELECT COALESCE(MAX(priority), 0) FROM $table WHERE schedule_id = ?", [$scheduleId]));
        }

        return $max + 1;
    }

    /** @param list<int> $weekdays */
    private function insertShiftType(int $scheduleId, string $name, string $start, string $end, array $weekdays, int $sort): int
    {
        $this->module->prepare('INSERT INTO ' . ScheduleRepository::SHIFT_TYPES . ' (schedule_id, name, start_time, end_time,
            weekdays, sort) VALUES (?, ?, ?, ?, ?, ?)')->execute([$scheduleId, $name, $start, $end, implode(',', $weekdays), $sort]);

        return (int)$this->module->lastInsertId();
    }

    private function audit(?int $scheduleId, string $action, mixed $before, mixed $after): void
    {
        $this->module->prepare('INSERT INTO ' . ScheduleRepository::AUDIT . ' (schedule_id, actor, action, before_json, after_json, at)
            VALUES (?, ?, ?, ?, ?, ?)')->execute([
                $scheduleId, $this->actor, $action,
                $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
                $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
                $this->now,
            ]);
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function transaction(callable $work): mixed
    {
        // IMMEDIATE takes the write lock at once, so a second admin's change
        // waits (busy_timeout) instead of failing when its lock is upgraded.
        $this->module->exec('BEGIN IMMEDIATE');
        try {
            $result = $work();
            $this->module->exec('COMMIT');

            return $result;
        } catch (Throwable $e) {
            $this->module->exec('ROLLBACK');
            throw $e;
        }
    }

    // ------------------------------------------------------------------ value checks

    private static function name(mixed $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string)$name) ?? '');
        // The core puts the name into its destination pickers as HTML, unescaped.
        if ($name === '' || mb_strlen($name) > 60 || preg_match('/[<>&"]/', $name) === 1) {
            throw new EditorException('occ_ErrName');
        }

        return $name;
    }

    private static function note(mixed $note): string
    {
        $note = trim((string)$note);
        if (mb_strlen($note) > 200) {
            throw new EditorException('occ_ErrNote');
        }

        return $note;
    }

    private static function ringSeconds(mixed $seconds): int
    {
        $seconds = (int)$seconds;
        if ($seconds < self::MIN_RING || $seconds > self::MAX_RING) {
            throw new EditorException('occ_ErrRingTime', ['min' => self::MIN_RING, 'max' => self::MAX_RING]);
        }

        return $seconds;
    }

    private static function time(mixed $time): string
    {
        $time = (string)$time;
        if (DutyResolver::minutes($time) === null) {
            throw new EditorException('occ_ErrTime');
        }

        return $time;
    }

    private static function date(mixed $date): string
    {
        $date = (string)$date;
        if (DutyResolver::dayNumber($date) === null) {
            throw new EditorException('occ_ErrDate');
        }

        return $date;
    }

    private static function localDateTime(mixed $value): string
    {
        $value = (string)$value;
        if (preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})$/', $value, $m) !== 1
            || DutyResolver::dayNumber($m[1]) === null || (DutyResolver::minutes($m[2]) ?? 1440) >= 1440) {
            throw new EditorException('occ_ErrDateTime');
        }

        return $value;
    }

    /** @return list<int> */
    private static function weekdays(mixed $weekdays): array
    {
        if (!is_array($weekdays)) {
            throw new EditorException('occ_ErrWeekdays');
        }
        $days = array_values(array_unique(array_map('intval', $weekdays)));
        sort($days);
        if ($days === [] || $days[0] < 1 || $days[count($days) - 1] > 7) {
            throw new EditorException('occ_ErrWeekdays');
        }

        return $days;
    }
}
