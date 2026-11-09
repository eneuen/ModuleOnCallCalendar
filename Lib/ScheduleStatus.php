<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Lib;

use DateTimeZone;

/**
 * What the module page's cards show for one schedule: who is on duty now
 * and until when, what comes next, and the gaps in the coming days. Pure:
 * the same resolver the phone system uses, so the card is never out of step
 * with the calls.
 */
final class ScheduleStatus
{
    public const int COVERAGE_DAYS = 14;

    /**
     * @param array<string, mixed> $plan ScheduleRepository::plan()
     * @return array{now: array<string, mixed>, next: ?array<string, mixed>, gap_seconds: int, first_gap: ?int, gap_now: bool,
     *     planned: bool}
     */
    public static function of(array $plan, DateTimeZone $tz, int $now): array
    {
        $segments = (new DutyResolver($plan, $tz))->segments($now, $now + self::COVERAGE_DAYS * 86400);
        $gapSeconds = 0;
        $firstGap = null;
        $planned = false;
        foreach ($segments as $segment) {
            // Someone to ring, a gap in covered hours, or a "send elsewhere": the schedule plans this time.
            $planned = $planned || $segment->start !== DutyResolver::START_OUTSIDE;
            if ($segment->start === DutyResolver::START_GAP) {
                $gapSeconds += $segment->to - $segment->from;
                $firstGap ??= $segment->from;
            }
        }
        $current = $segments[0];
        $next = $segments[1] ?? null;

        return [
            'now'         => $current->toArray(),
            'next'        => $next?->toArray(),
            'gap_seconds' => $gapSeconds,
            'first_gap'   => $firstGap,
            'gap_now'     => $current->start === DutyResolver::START_GAP,
            'planned'     => $planned,
        ];
    }
}
