<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Modules\ModuleOnCallCalendar\Lib\ScheduleStatus;
use PHPUnit\Framework\TestCase;

final class ScheduleStatusTest extends TestCase
{
    public function testNowNextAndGaps(): void
    {
        $tz = new DateTimeZone('Europe/Berlin');
        $plan = [
            'members' => [1 => ['name' => 'Anna', 'extension' => '201']],
            'shift_types' => [['id' => 1, 'start' => '08:00', 'end' => '13:00', 'weekdays' => [1, 2, 3, 4, 5], 'sort' => 1]],
            'rosters' => [['id' => 1, 'priority' => 1, 'valid_from' => '2026-11-02', 'cycle_weeks' => 1, 'cells' => [
                ['shift_type_id' => 1, 'week' => 1, 'weekday' => 1, 'member_id' => 1],
            ]]],
        ];
        $now = (new DateTimeImmutable('2026-11-09 09:00', $tz))->getTimestamp();
        $status = ScheduleStatus::of($plan, $tz, $now);

        self::assertSame('1', $status['now']['start']);
        self::assertSame((new DateTimeImmutable('2026-11-09 13:00', $tz))->getTimestamp(), $status['now']['to']);
        self::assertSame('out', $status['next']['start']);
        self::assertFalse($status['gap_now']);
        // Tuesday to Friday mornings of both weeks are empty (Mondays are Anna): 8 mornings of 5 hours.
        self::assertSame(8 * 5 * 3600, $status['gap_seconds']);
        self::assertSame((new DateTimeImmutable('2026-11-10 08:00', $tz))->getTimestamp(), $status['first_gap']);
    }
}
