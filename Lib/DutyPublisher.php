<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Lib;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Turns each enabled schedule's current decision into the AstDB values the
 * dialplan reads (see DialplanBuilder). Pure: the worker supplies the plans
 * and writes the result; the AGI fallback uses the same values for a live
 * answer.
 *
 * "until" is the next moment the decision changes, but never more than
 * MAX_AGE seconds ahead. The worker republishes every minute, so while it
 * runs the dialplan always finds a fresh answer; if it stops, answers expire
 * within MAX_AGE and every call asks the AGI fallback instead, which reads
 * the database itself.
 */
final class DutyPublisher
{
    public const int MAX_AGE = 180;

    /**
     * @param list<array<string, mixed>> $schedules rows of m_Schedules
     * @param callable(int, string): array<string, mixed> $planFor schedule id and "Y-m-d H:i" (now) → plan
     * @return array<int, array{start: string, dial: string, goto: string, backup: string, until: string}> keyed by schedule id
     */
    public static function values(array $schedules, callable $planFor, DateTimeZone $tz, int $now): array
    {
        $since = (new DateTimeImmutable('@' . ($now - 86400)))->setTimezone($tz)->format('Y-m-d H:i');
        $values = [];
        foreach ($schedules as $schedule) {
            if ((string)($schedule['enabled'] ?? '1') !== '1') {
                continue;
            }
            $id = (int)$schedule['id'];
            try {
                $segment = (new DutyResolver($planFor($id, $since), $tz))->at($now);
            } catch (Throwable) {
                // Leave this schedule out: its old answer expires and the AGI fallback decides.
                continue;
            }
            $values[$id] = self::forSegment($segment, $now);
        }

        return $values;
    }

    /** @return array{start: string, dial: string, goto: string, backup: string, until: string} */
    public static function forSegment(DutySegment $segment, int $now): array
    {
        $start = $segment->start;
        $goto = '';
        // Dial() splits its argument at commas, so only Local legs to plain extensions are published.
        $dial = $start === DutyResolver::START_ON_DUTY ? $segment->dialString() : '';
        if ($dial !== '' && !self::isDialString($dial)) {
            $start = DutyResolver::START_GAP;
            $dial = '';
        }
        if ($start === DutyResolver::START_ELSEWHERE) {
            $goto = $segment->destination;
            if (!self::isDestination($goto)) {
                $start = DutyResolver::START_GAP;
                $goto = '';
            }
        }

        return [
            'start' => $start,
            'dial'  => $dial,
            'goto'  => $goto,
            // The deciding rule's own backup; a malformed one falls back to the schedule's.
            'backup' => BackupSpec::isValid($segment->backup) ? $segment->backup : '',
            'until' => (string)min($segment->to, $now + self::MAX_AGE),
        ];
    }

    /** Legs the dialplan may ring: Local/<ext>@internal-users/n joined by &. */
    public static function isDialString(string $dial): bool
    {
        return preg_match('~^Local/\d{1,20}@internal-users/n(&Local/\d{1,20}@internal-users/n)*$~D', $dial) === 1;
    }

    /** A number the dialplan may jump to: digits, or one of the core's named system extensions. */
    public static function isDestination(string $number): bool
    {
        return preg_match('/^(\d{1,20}|voicemail|hangup|busy)$/', $number) === 1;
    }
}
