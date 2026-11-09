<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Tests\Unit;

use Modules\ModuleOnCallCalendar\Lib\ActivityReport;
use Modules\ModuleOnCallCalendar\Lib\DutyResolver;
use Modules\ModuleOnCallCalendar\Lib\DutySegment;
use PHPUnit\Framework\TestCase;

final class ActivityReportTest extends TestCase
{
    public function testOutcomesWaitsHoursAndAnswers(): void
    {
        $members = [
            ['id' => 1, 'user_id' => 110, 'name' => 'Anna', 'extension' => '201'],
            ['id' => 2, 'user_id' => 111, 'name' => 'Ben', 'extension' => '202'],
            ['id' => 3, 'user_id' => 112, 'name' => 'Gone', 'extension' => null],
        ];
        $segments = [
            new DutySegment(0, 3 * 3600, DutyResolver::START_ON_DUTY, 'roster', 1, [1], ['201']),
            new DutySegment(3 * 3600, 4 * 3600, DutyResolver::START_GAP, 'roster', 1, [3], []),
            new DutySegment(4 * 3600, 6 * 3600, DutyResolver::START_ON_DUTY, 'rotation', 2, [1, 2], ['201', '202']),
        ];
        $calls = [
            ['outcome' => 'on_duty', 'answered_by' => '201', 'wait_seconds' => 4],
            ['outcome' => 'on_duty', 'answered_by' => '201', 'wait_seconds' => 10],
            ['outcome' => 'other', 'answered_by' => '202', 'wait_seconds' => 30],
            ['outcome' => 'voicemail', 'answered_by' => 'voicemail', 'wait_seconds' => 40],
            ['outcome' => 'unanswered', 'answered_by' => null, 'wait_seconds' => null],
        ];
        $report = ActivityReport::build($calls, $segments, $members);

        self::assertSame(5, $report['calls']);
        self::assertSame(['on_duty' => 2, 'other' => 1, 'voicemail' => 1, 'unanswered' => 1], $report['outcomes']);
        // Voicemail answering is not a person answering.
        self::assertSame(10, $report['median_wait']);
        self::assertSame([
            ['name' => 'Anna', 'extension' => '201', 'hours' => 5.0, 'answered' => 2, 'answered_on_duty' => 2, 'median_wait' => 7],
            ['name' => 'Ben', 'extension' => '202', 'hours' => 2.0, 'answered' => 1, 'answered_on_duty' => 0, 'median_wait' => 30],
        ], $report['people']);
    }

    public function testSomeoneWhoAnsweredButIsNoLongerAMemberIsStillCounted(): void
    {
        $calls = [['outcome' => 'other', 'answered_by' => '304', 'wait_seconds' => 12]];
        $report = ActivityReport::build($calls, [], [], [186 => ['name' => 'Dee', 'extension' => '304']]);

        self::assertSame([['name' => 'Dee', 'extension' => '304', 'hours' => 0.0, 'answered' => 1, 'answered_on_duty' => 0,
            'median_wait' => 12]], $report['people']);
    }

    public function testNoCalls(): void
    {
        $report = ActivityReport::build([], [], []);

        self::assertSame(0, $report['calls']);
        self::assertNull($report['median_wait']);
        self::assertSame([], $report['people']);
    }
}
