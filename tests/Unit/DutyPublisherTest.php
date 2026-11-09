<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Modules\ModuleOnCallCalendar\Lib\DutyPublisher;
use Modules\ModuleOnCallCalendar\Lib\DutyResolver;
use Modules\ModuleOnCallCalendar\Lib\DutySegment;
use Modules\ModuleOnCallCalendar\Lib\Employees;
use Modules\ModuleOnCallCalendar\Lib\ModuleContext;
use Modules\ModuleOnCallCalendar\Lib\ScheduleRepository;
use Modules\ModuleOnCallCalendar\Tests\Unit\Support\TestDatabases;
use PDO;
use PHPUnit\Framework\TestCase;

final class DutyPublisherTest extends TestCase
{
    private static function ts(string $local): int
    {
        return (new DateTimeImmutable($local, new DateTimeZone('Europe/Berlin')))->getTimestamp();
    }

    /** A Service-line schedule stored as the editor stores it: Anna Mon–Fri mornings. */
    private static function moduleWithServiceLine(): PDO
    {
        $db = TestDatabases::module();
        $db->exec("INSERT INTO m_Schedules (id, name, number, ring_seconds, backup_ring_seconds, final_number, template, enabled)
            VALUES (1, 'Service line', '2202', 20, 20, 'voicemail', 'shift', '1'),
                   (2, 'Off line', '2203', 20, 20, 'voicemail', 'blank', '0')");
        $db->exec("INSERT INTO m_Members (id, schedule_id, user_id, colour) VALUES (1, 1, 110, '#2185d0'), (2, 1, 111, ''), (3, 1, 999, '')");
        $db->exec("INSERT INTO m_ShiftTypes (id, schedule_id, name, start_time, end_time, weekdays, sort)
            VALUES (10, 1, 'Morning', '08:00', '13:00', '1,2,3,4,5', 1)");
        $db->exec("INSERT INTO m_Rosters (id, schedule_id, priority, valid_from, valid_until, cycle_weeks) VALUES (1, 1, 1, '2026-11-02', '', 1)");
        foreach ([1, 2, 3, 4, 5] as $weekday) {
            $db->exec("INSERT INTO m_RosterCells (roster_id, shift_type_id, week, weekday, member_id) VALUES (1, 10, 1, $weekday, 1)");
        }

        return $db;
    }

    public function testPublishesEnabledSchedulesFromTheStoredPlan(): void
    {
        $core = TestDatabases::core([110 => ['Anna', '201'], 111 => ['Ben', '202']]);
        $values = ModuleContext::currentValues(self::moduleWithServiceLine(), $core, self::ts('2026-11-09 09:00'));

        self::assertSame([1], array_keys($values));
        self::assertSame('1', $values[1]['start']);
        self::assertSame('Local/201@internal-users/n', $values[1]['dial']);
        self::assertSame((string)(self::ts('2026-11-09 09:00') + DutyPublisher::MAX_AGE), $values[1]['until']);
    }

    public function testUntilIsTheNextChangeWhenSooner(): void
    {
        $core = TestDatabases::core([110 => ['Anna', '201']]);
        $values = ModuleContext::currentValues(self::moduleWithServiceLine(), $core, self::ts('2026-11-09 12:59'));

        self::assertSame((string)self::ts('2026-11-09 13:00'), $values[1]['until']);
    }

    public function testEmployeeWithoutExtensionMakesAGap(): void
    {
        $core = TestDatabases::core([110 => ['Anna', null]]);
        $values = ModuleContext::currentValues(self::moduleWithServiceLine(), $core, self::ts('2026-11-09 09:00'));

        self::assertSame(['start' => '2', 'dial' => '', 'goto' => '', 'backup' => ''], array_diff_key($values[1], ['until' => 0]));
    }

    public function testSendElsewhereWritesTheDestination(): void
    {
        $segment = new DutySegment(0, 100, DutyResolver::START_ELSEWHERE, DutyResolver::BY_OVERRIDE, 1, [], [], '2003');

        self::assertSame(['start' => 'go', 'dial' => '', 'goto' => '2003', 'backup' => '', 'until' => '100'], DutyPublisher::forSegment($segment, 0));
    }

    public function testInvalidDestinationFallsBackToAGap(): void
    {
        $segment = new DutySegment(0, 100, DutyResolver::START_ELSEWHERE, DutyResolver::BY_OVERRIDE, 1, [], [], '20,1)');

        self::assertSame('2', DutyPublisher::forSegment($segment, 0)['start']);
        self::assertSame('', DutyPublisher::forSegment($segment, 0)['goto']);
    }

    public function testTheDecidingRulesBackupIsPublished(): void
    {
        $db = self::moduleWithServiceLine();
        $db->exec("UPDATE m_Rosters SET backup_type = 'member', backup_user_id = 111, backup_ring_seconds = 15 WHERE id = 1");
        $core = TestDatabases::core([110 => ['Anna', '201'], 111 => ['Ben', '202']]);

        self::assertSame('dial|15|Local/202@internal-users/n', ModuleContext::currentValues($db, $core, self::ts('2026-11-09 09:00'))[1]['backup']);
        // Outside the roster's shifts the schedule's own backup applies.
        self::assertSame('', ModuleContext::currentValues($db, $core, self::ts('2026-11-09 20:00'))[1]['backup']);

        $db->exec("UPDATE m_Rosters SET backup_type = 'none' WHERE id = 1");
        self::assertSame('none', ModuleContext::currentValues($db, $core, self::ts('2026-11-09 09:00'))[1]['backup']);
        $db->exec("UPDATE m_Rosters SET backup_type = 'queue', backup_number = '2000' WHERE id = 1");
        self::assertSame('queue|2000', ModuleContext::currentValues($db, $core, self::ts('2026-11-09 09:00'))[1]['backup']);
    }

    public function testMalformedBackupSpecsAreNotPublished(): void
    {
        foreach (['dial|20|Local/202@internal-users/n,1)', 'queue|20,1', 'x'] as $spec) {
            $segment = new DutySegment(0, 100, DutyResolver::START_ON_DUTY, DutyResolver::BY_ROSTER, 1, [1], ['201'], '', $spec);
            self::assertSame('', DutyPublisher::forSegment($segment, 0)['backup'], $spec);
        }
    }

    public function testRepositoryResolvesMembersThroughTheEmployeeDirectory(): void
    {
        $core = TestDatabases::core([110 => ['Anna', '201'], 111 => ['Ben', null]]);
        $members = (new ScheduleRepository(self::moduleWithServiceLine()))->members(1, Employees::directory($core));

        self::assertSame(['Anna', '', ''], array_column($members, 'name'));
        self::assertSame(['201', null, null], array_column($members, 'extension'));
    }

    public function testTimezoneComesFromThePbxSettings(): void
    {
        self::assertSame('Asia/Bangkok', ModuleContext::timezone(TestDatabases::core([], 'Asia/Bangkok'))->getName());
        self::assertSame('Europe/Moscow', ModuleContext::timezone(TestDatabases::core([], 'Not/AZone'))->getName());
    }
}
