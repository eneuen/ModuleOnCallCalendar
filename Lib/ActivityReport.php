<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Lib;

/**
 * The activity report for one schedule and date range: is the plan working
 * (how calls ended, how fast they were answered) and is it fair (hours on
 * duty and calls answered per person). Pure: it gets the call log rows, the
 * resolver's segments for the range and the schedule's members.
 */
final class ActivityReport
{
    /**
     * @param list<array<string, mixed>> $calls m_Calls rows in the range
     * @param list<DutySegment> $segments the schedule's decisions over the range
     * @param list<array{id: int, user_id: int, name: string, extension: ?string}> $members ScheduleRepository::members()
     * @param array<int, array{name: string, extension: string}> $directory Employees::directory(), to name people who
     *     answered but are no longer members
     * @return array{calls: int, outcomes: array<string, int>, median_wait: ?int, people: list<array<string, mixed>>}
     */
    public static function build(array $calls, array $segments, array $members, array $directory = []): array
    {
        $outcomes = [CallLog::ON_DUTY => 0, CallLog::OTHER => 0, CallLog::VOICEMAIL => 0, CallLog::UNANSWERED => 0];
        $waits = [];
        $people = [];
        $byExtension = [];
        foreach ($members as $member) {
            $people[$member['id']] = ['name' => $member['name'], 'extension' => $member['extension'], 'hours' => 0.0,
                'answered' => 0, 'answered_on_duty' => 0, 'waits' => []];
            if ($member['extension'] !== null && $member['extension'] !== '') {
                $byExtension[$member['extension']] = $member['id'];
            }
        }

        foreach ($segments as $segment) {
            if ($segment->start !== DutyResolver::START_ON_DUTY) {
                continue;
            }
            foreach ($segment->memberIds as $memberId) {
                if (isset($people[$memberId]) && in_array((string)$people[$memberId]['extension'], $segment->targets, true)) {
                    $people[$memberId]['hours'] += ($segment->to - $segment->from) / 3600;
                }
            }
        }

        foreach ($calls as $call) {
            $outcome = (string)$call['outcome'];
            $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
            if ($call['wait_seconds'] !== null && $outcome !== CallLog::VOICEMAIL) {
                $waits[] = (int)$call['wait_seconds'];
            }
            $answeredBy = (string)$call['answered_by'];
            $memberId = $byExtension[$answeredBy] ?? null;
            if ($memberId === null && $answeredBy !== '' && $answeredBy !== 'voicemail') {
                // Someone who is not (or no longer) a member: still counted, under their employee name.
                $memberId = 'ext:' . $answeredBy;
                $name = '';
                foreach ($directory as $employee) {
                    $name = $employee['extension'] === $answeredBy ? $employee['name'] : $name;
                }
                $people[$memberId] ??= ['name' => $name, 'extension' => $answeredBy, 'hours' => 0.0, 'answered' => 0,
                    'answered_on_duty' => 0, 'waits' => []];
                $byExtension[$answeredBy] = $memberId;
            }
            if ($memberId !== null) {
                $people[$memberId]['answered']++;
                $people[$memberId]['answered_on_duty'] += $outcome === CallLog::ON_DUTY ? 1 : 0;
                if ($call['wait_seconds'] !== null) {
                    $people[$memberId]['waits'][] = (int)$call['wait_seconds'];
                }
            }
        }

        $rows = [];
        foreach ($people as $person) {
            if ($person['hours'] <= 0 && $person['answered'] === 0) {
                continue;
            }
            $rows[] = [
                'name' => $person['name'], 'extension' => $person['extension'], 'hours' => round($person['hours'], 1),
                'answered' => $person['answered'], 'answered_on_duty' => $person['answered_on_duty'],
                'median_wait' => self::median($person['waits']),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => [$b['hours'], $a['name']] <=> [$a['hours'], $b['name']]);

        return ['calls' => count($calls), 'outcomes' => $outcomes, 'median_wait' => self::median($waits), 'people' => $rows];
    }

    /** @param list<int> $values */
    private static function median(array $values): ?int
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n = count($values);

        return (int)round($n % 2 === 1 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2);
    }
}
