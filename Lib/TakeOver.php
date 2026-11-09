<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Lib;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Taking over duty from a phone: a member dials *22 (or *22 followed by a
 * schedule's number) and is on duty from now until the plan next changes
 * (the next handover or end of shift), for at most a week. It is stored as an ordinary "take over" override, so it
 * shows on the calendar, is audited, and can be moved or deleted there.
 *
 * Who is calling comes from the PJSIP endpoint the call arrived on, not from
 * a caller ID the phone sends.
 */
final class TakeOver
{
    public const string OK = 'ok';
    public const string NOT_AN_EMPLOYEE = 'not_an_employee';
    public const string NOT_A_MEMBER = 'not_a_member';
    public const string WHICH_SCHEDULE = 'which_schedule';

    private const int LONGEST = 7 * 86400;

    /**
     * @param PDO $module the module's database, writable
     * @param PDO $core the core's configuration database
     * @param string $endpoint the caller's PJSIP endpoint (e.g. 301, or 301-WS for a WebRTC phone)
     * @param string $number the schedule's number dialled after *22; '' for "the one I belong to"
     * @return array{result: string, schedule_id?: int, until?: int}
     */
    public static function take(PDO $module, PDO $core, string $endpoint, string $number, int $now): array
    {
        $extension = (string)preg_replace('/-(WS|TLS)$/', '', $endpoint);
        $userId = null;
        foreach (Employees::directory($core) as $id => $employee) {
            if ($employee['extension'] === $extension) {
                $userId = $id;
            }
        }
        if ($userId === null || preg_match('/^\d+$/', $extension) !== 1) {
            return ['result' => self::NOT_AN_EMPLOYEE];
        }

        $repository = new ScheduleRepository($module);
        $mine = [];
        foreach ($repository->schedules() as $schedule) {
            $isMember = in_array($userId, array_column($repository->members((int)$schedule['id'], []), 'user_id'), true);
            if ($isMember && (string)$schedule['enabled'] === '1' && ($number === '' || (string)$schedule['number'] === $number)) {
                $mine[] = $schedule;
            }
        }
        if ($mine === []) {
            return ['result' => self::NOT_A_MEMBER];
        }
        if (count($mine) > 1) {
            return ['result' => self::WHICH_SCHEDULE];
        }
        $schedule = $mine[0];
        $id = (int)$schedule['id'];

        $tz = ModuleContext::timezone($core);
        $segment = (new DutyResolver($repository->plan($id, Employees::directory($core)), $tz))->at($now);
        $until = min($segment->to, $now + self::LONGEST);
        if ($segment->start === DutyResolver::START_ON_DUTY && $segment->targets === [$extension]) {
            // Already the only one on duty: nothing to change.
            return ['result' => self::OK, 'schedule_id' => $id, 'until' => $until];
        }
        $local = static fn (int $t): string => (new DateTimeImmutable('@' . $t))->setTimezone($tz)->format('Y-m-d H:i');
        $start = $local($now);
        $end = $local($until);
        if ($end <= $start) {
            $end = $local($now + 60);
        }
        (new ScheduleEditor($module, $core, "phone $extension", $now))->addOverride($id, [
            'kind' => DutyResolver::KIND_TAKEOVER, 'start_at' => $start, 'end_at' => $end, 'user_ids' => [$userId],
            'note' => '*22',
        ]);

        return ['result' => self::OK, 'schedule_id' => $id, 'until' => $until];
    }
}
