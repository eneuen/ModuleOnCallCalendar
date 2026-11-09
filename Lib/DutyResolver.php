<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Lib;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Decides who is on duty in one schedule, for any moment or time range. The
 * calendar, the module page, the publisher and the AGI fallback all use it,
 * so what the page shows is what the phone system does.
 *
 * Pure PHP: it gets the schedule's plan as arrays (see ScheduleRepository::
 * plan()) and a time zone, and never reads a clock or a database.
 *
 * For a moment t, in order:
 *  1. An override covering t decides. If several overlap, the newest wins.
 *  2. Otherwise the first rule (roster or rotation, lowest priority number)
 *     that covers t decides. Inside a roster, where shift types overlap, the
 *     one lower in the list (higher sort) wins. A covered moment with nobody
 *     assigned, or only people without an extension, is a gap: the call
 *     starts at the backup.
 *  3. Otherwise t is outside covered hours.
 *
 * A roster or rotation may carry its own backup ("backup", a BackupSpec
 * string); while it decides (someone on duty, or a gap in it) that backup
 * replaces the schedule's.
 *
 * All times in the plan are wall-clock times in the PBX's time zone:
 * - A shift belongs to the day it starts; one that ends at or before its
 *   start time ends the next day (start = end means 24 hours). On
 *   daylight-saving changes a shift is an hour shorter or longer.
 * - A roster repeats over cycle_weeks weeks. Week 1 is the week (Monday to
 *   Sunday) that contains valid_from.
 * - A rotation hands over every turn_days days at the time of
 *   first_handover; turn k goes to members[k mod count]. Active hours, if
 *   set, limit each turn to daily windows.
 */
final class DutyResolver
{
    public const string START_ON_DUTY = '1';
    public const string START_GAP = '2';
    public const string START_OUTSIDE = 'out';
    public const string START_ELSEWHERE = 'go';

    public const string BY_OVERRIDE = 'override';
    public const string BY_ROSTER = 'roster';
    public const string BY_ROTATION = 'rotation';
    public const string BY_NONE = 'none';

    public const string KIND_COVER = 'cover';
    public const string KIND_SWAP = 'swap';
    public const string KIND_TAKEOVER = 'takeover';
    public const string KIND_ELSEWHERE = 'elsewhere';

    private const int DAY = 86400;

    /** How far at() looks ahead for the next change. */
    private const int LOOKAHEAD = 14 * self::DAY;

    /**
     * @param array{
     *     shift_types?: list<array{id: int, start: string, end: string, weekdays: list<int>, sort: int}>,
     *     rosters?: list<array{id: int, priority: int, valid_from: string, valid_until?: string, cycle_weeks: int,
     *         cells: list<array{shift_type_id: int, week: int, weekday: int, member_id: int}>}>,
     *     rotations?: list<array{id: int, priority: int, members: list<int>, first_handover: string, turn_days: int,
     *         active_hours?: list<array{weekdays: list<int>, start: string, end: string}>, valid_until?: string}>,
     *     overrides?: list<array{id: int, kind: string, start_at: string, end_at: string, members?: list<int>,
     *         destination?: string}>,
     *     members?: array<int, array{name: string, extension: ?string}>
     * } $plan
     */
    public function __construct(private readonly array $plan, private readonly DateTimeZone $tz)
    {
    }

    /** The decision at $t; its end is the next moment the decision can change. */
    public function at(int $t): DutySegment
    {
        return $this->segments($t, $t + self::LOOKAHEAD)[0];
    }

    /**
     * The decisions covering [$from, $to), in order, adjacent equal decisions
     * merged. The first segment starts at $from and the last ends at $to.
     *
     * @return list<DutySegment>
     */
    public function segments(int $from, int $to): array
    {
        if ($to <= $from) {
            $to = $from + 1;
        }
        $items = [...$this->overrideItems($from, $to), ...$this->rosterItems($from, $to), ...$this->rotationItems($from, $to)];

        $bounds = [$from => true, $to => true];
        foreach ($items as $item) {
            if ($item['from'] > $from && $item['from'] < $to) {
                $bounds[$item['from']] = true;
            }
            if ($item['to'] > $from && $item['to'] < $to) {
                $bounds[$item['to']] = true;
            }
        }
        $bounds = array_keys($bounds);
        sort($bounds);

        $segments = [];
        for ($i = 0, $n = count($bounds) - 1; $i < $n; $i++) {
            [$a, $b] = [$bounds[$i], $bounds[$i + 1]];
            $segment = $this->decide($items, $a, $b);
            $last = $segments === [] ? null : $segments[count($segments) - 1];
            if ($last !== null && $last->sameDecisionAs($segment)) {
                $segments[count($segments) - 1] = $last->extendedTo($b);
            } else {
                $segments[] = $segment;
            }
        }

        return $segments;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function decide(array $items, int $a, int $b): DutySegment
    {
        $winner = null;
        foreach ($items as $item) {
            if ($item['from'] > $a || $item['to'] < $b) {
                continue;
            }
            if ($winner === null || self::outranks($item, $winner)) {
                $winner = $item;
            }
        }
        if ($winner === null) {
            return new DutySegment($a, $b, self::START_OUTSIDE, self::BY_NONE, 0);
        }
        if ($winner['kind'] === self::KIND_ELSEWHERE) {
            return new DutySegment($a, $b, self::START_ELSEWHERE, $winner['by'], $winner['id'], [], [], (string)$winner['destination']);
        }

        $memberIds = [];
        $targets = [];
        foreach ($winner['members'] as $memberId) {
            $memberIds[] = $memberId;
            $extension = $this->plan['members'][$memberId]['extension'] ?? null;
            if ($extension !== null && $extension !== '' && !in_array($extension, $targets, true)) {
                $targets[] = $extension;
            }
        }
        $start = $targets === [] ? self::START_GAP : self::START_ON_DUTY;

        return new DutySegment($a, $b, $start, $winner['by'], $winner['id'], $memberIds, $targets, '', (string)($winner['backup'] ?? ''));
    }

    /**
     * Overrides beat rules; among overrides the newest (highest id) wins; among
     * rules the lowest priority number wins, then the shift type lower in the
     * roster's list.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $other
     */
    private static function outranks(array $item, array $other): bool
    {
        $itemOverride = $item['by'] === self::BY_OVERRIDE;
        $otherOverride = $other['by'] === self::BY_OVERRIDE;
        if ($itemOverride !== $otherOverride) {
            return $itemOverride;
        }
        if ($itemOverride) {
            return $item['id'] > $other['id'];
        }
        if ($item['priority'] !== $other['priority']) {
            return $item['priority'] < $other['priority'];
        }

        return $item['sort'] > $other['sort'];
    }

    /** @return list<array<string, mixed>> */
    private function overrideItems(int $from, int $to): array
    {
        $items = [];
        foreach ($this->plan['overrides'] ?? [] as $o) {
            $start = $this->localTimestamp((string)$o['start_at']);
            $end = $this->localTimestamp((string)$o['end_at']);
            if ($start === null || $end === null || $end <= $start || $end <= $from || $start >= $to) {
                continue;
            }
            $items[] = [
                'from' => $start, 'to' => $end, 'by' => self::BY_OVERRIDE, 'id' => (int)$o['id'],
                'kind' => (string)$o['kind'], 'members' => array_map('intval', $o['members'] ?? []),
                'destination' => (string)($o['destination'] ?? ''), 'priority' => 0, 'sort' => 0,
            ];
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function rosterItems(int $from, int $to): array
    {
        $shiftTypes = $this->plan['shift_types'] ?? [];
        $items = [];
        foreach ($this->plan['rosters'] ?? [] as $roster) {
            $cycle = max(1, min(4, (int)$roster['cycle_weeks']));
            $firstDay = self::dayNumber((string)$roster['valid_from']);
            $lastDay = ($roster['valid_until'] ?? '') === '' ? null : self::dayNumber((string)$roster['valid_until']);
            if ($firstDay === null) {
                continue;
            }
            $anchor = $firstDay - (self::isoWeekday($firstDay) - 1);
            $cells = [];
            foreach ($roster['cells'] as $cell) {
                $cells[(int)$cell['shift_type_id']][(int)$cell['week']][(int)$cell['weekday']][] = (int)$cell['member_id'];
            }
            foreach ($this->days($from, $to) as $day) {
                if ($day < $firstDay || ($lastDay !== null && $day > $lastDay)) {
                    continue;
                }
                $weekday = self::isoWeekday($day);
                $week = intdiv($day - $anchor, 7) % $cycle + 1;
                foreach ($shiftTypes as $shift) {
                    if (!in_array($weekday, array_map('intval', $shift['weekdays']), true)) {
                        continue;
                    }
                    $span = $this->dailySpan($day, (string)$shift['start'], (string)$shift['end']);
                    if ($span === null || $span[1] <= $from || $span[0] >= $to) {
                        continue;
                    }
                    $items[] = [
                        'from' => $span[0], 'to' => $span[1], 'by' => self::BY_ROSTER, 'id' => (int)$roster['id'],
                        'kind' => '', 'members' => $cells[(int)$shift['id']][$week][$weekday] ?? [],
                        'priority' => (int)$roster['priority'], 'sort' => (int)$shift['sort'], 'backup' => (string)($roster['backup'] ?? ''),
                    ];
                }
            }
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function rotationItems(int $from, int $to): array
    {
        $items = [];
        foreach ($this->plan['rotations'] ?? [] as $rotation) {
            $members = array_map('intval', $rotation['members']);
            $turnDays = max(1, (int)$rotation['turn_days']);
            $first = (string)$rotation['first_handover'];
            $firstDay = self::dayNumber(substr($first, 0, 10));
            $handoverTime = substr($first, 11, 5);
            if ($members === [] || $firstDay === null || self::minutes($handoverTime) === null) {
                continue;
            }
            $until = ($rotation['valid_until'] ?? '') === '' ? null : self::dayNumber((string)$rotation['valid_until']);
            $limit = $until === null ? $to : min($to, (int)$this->localTimestamp(self::dateOf($until + 1) . ' 00:00'));

            $fromDay = $this->dayOf($from);
            $k = max(0, intdiv(max(0, $fromDay - $firstDay), $turnDays) - 1);
            for (; ; $k++) {
                $turnStart = $this->localTimestamp(self::dateOf($firstDay + $k * $turnDays) . " $handoverTime");
                $turnEnd = $this->localTimestamp(self::dateOf($firstDay + ($k + 1) * $turnDays) . " $handoverTime");
                if ($turnStart === null || $turnEnd === null || $turnStart >= $limit) {
                    break;
                }
                $turnEnd = min($turnEnd, $limit);
                if ($turnEnd <= $from) {
                    continue;
                }
                $member = $members[$k % count($members)];
                foreach ($this->activeSpans($rotation['active_hours'] ?? [], $turnStart, $turnEnd) as [$a, $b]) {
                    $items[] = [
                        'from' => $a, 'to' => $b, 'by' => self::BY_ROTATION, 'id' => (int)$rotation['id'],
                        'kind' => '', 'members' => [$member], 'priority' => (int)$rotation['priority'], 'sort' => 0,
                        'backup' => (string)($rotation['backup'] ?? ''),
                    ];
                }
            }
        }

        return $items;
    }

    /**
     * The parts of [$start, $end) inside the daily active-hours windows; the
     * whole range when there are none.
     *
     * @param list<array{weekdays: list<int>, start: string, end: string}> $windows
     * @return list<array{0: int, 1: int}>
     */
    private function activeSpans(array $windows, int $start, int $end): array
    {
        if ($windows === []) {
            return [[$start, $end]];
        }
        $spans = [];
        foreach ($this->days($start, $end) as $day) {
            $weekday = self::isoWeekday($day);
            foreach ($windows as $window) {
                if (!in_array($weekday, array_map('intval', $window['weekdays']), true)) {
                    continue;
                }
                $span = $this->dailySpan($day, (string)$window['start'], (string)$window['end']);
                if ($span === null) {
                    continue;
                }
                $a = max($start, $span[0]);
                $b = min($end, $span[1]);
                if ($a < $b) {
                    $spans[] = [$a, $b];
                }
            }
        }

        return $spans;
    }

    /**
     * A daily window on $day: [start, end), ending the next day when end is
     * at or before start (equal means 24 hours). "24:00" is midnight at the
     * end of the day.
     *
     * @return array{0: int, 1: int}|null
     */
    private function dailySpan(int $day, string $start, string $end): ?array
    {
        $startMinutes = self::minutes($start);
        $endMinutes = self::minutes($end);
        if ($startMinutes === null || $endMinutes === null) {
            return null;
        }
        $endDay = $endMinutes <= $startMinutes ? $day + 1 : $day;
        $a = $this->wallClock($day, $startMinutes);
        $b = $this->wallClock($endDay, $endMinutes);

        return $a !== null && $b !== null && $b > $a ? [$a, $b] : null;
    }

    /** The timestamp of $minutes after midnight on $day, PBX time ("24:00" rolls over). */
    private function wallClock(int $day, int $minutes): ?int
    {
        $day += intdiv($minutes, 1440);
        $minutes %= 1440;

        return $this->localTimestamp(sprintf('%s %02d:%02d', self::dateOf($day), intdiv($minutes, 60), $minutes % 60));
    }

    /**
     * Local day numbers from the day before $from to the day of $to: the day
     * before catches overnight shifts that started then.
     *
     * @return list<int>
     */
    private function days(int $from, int $to): array
    {
        return range($this->dayOf($from) - 1, $this->dayOf($to));
    }

    /** "Y-m-d H:i" in PBX time as a timestamp. */
    private function localTimestamp(string $local): ?int
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $local) !== 1) {
            return null;
        }
        try {
            $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $local, $this->tz);
        } catch (Throwable) {
            return null;
        }

        return $time === false ? null : $time->getTimestamp();
    }

    /** The local day number of a timestamp. */
    private function dayOf(int $timestamp): int
    {
        $date = (new DateTimeImmutable('@' . $timestamp))->setTimezone($this->tz)->format('Y-m-d');

        return (int)self::dayNumber($date);
    }

    /** Days since 1970-01-01 for a "Y-m-d" date, independent of time zones. */
    public static function dayNumber(string $date): ?int
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1 || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return null;
        }

        return intdiv(gmmktime(0, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]), self::DAY);
    }

    public static function dateOf(int $dayNumber): string
    {
        return gmdate('Y-m-d', $dayNumber * self::DAY);
    }

    /** 1 = Monday … 7 = Sunday. */
    public static function isoWeekday(int $dayNumber): int
    {
        // 1970-01-01 was a Thursday (4).
        return ($dayNumber + 3) % 7 + 1;
    }

    /** "HH:MM" (00:00 to 24:00) as minutes after midnight. */
    public static function minutes(string $time): ?int
    {
        if (preg_match('/^(\d{2}):(\d{2})$/', $time, $m) !== 1) {
            return null;
        }
        $minutes = (int)$m[1] * 60 + (int)$m[2];

        return (int)$m[2] < 60 && $minutes <= 1440 ? $minutes : null;
    }
}
