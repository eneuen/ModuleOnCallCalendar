<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Modules\ModuleOnCallCalendar\Lib\DutyResolver;
use Modules\ModuleOnCallCalendar\Lib\Employees;
use Modules\ModuleOnCallCalendar\Lib\ScheduleEditor;
use Modules\ModuleOnCallCalendar\Lib\ScheduleRepository;
use Modules\ModuleOnCallCalendar\Lib\TakeOver;
use Modules\ModuleOnCallCalendar\Tests\Unit\Support\TestDatabases;
use PDO;
use PHPUnit\Framework\TestCase;

final class TakeOverTest extends TestCase
{
    private PDO $module;

    private PDO $core;

    private int $line;

    private static function ts(string $local): int
    {
        return (new DateTimeImmutable($local, new DateTimeZone('Europe/Berlin')))->getTimestamp();
    }

    protected function setUp(): void
    {
        $this->module = TestDatabases::module();
        $this->core = TestDatabases::core([110 => ['Anna', '201'], 111 => ['Ben', '202'], 112 => ['Carla', '203']]);
        $editor = new ScheduleEditor($this->module, $this->core, 'admin', self::ts('2026-11-11 10:00'));
        // A rotation of Anna and Ben, handing over every Friday at 17:00.
        $this->line = $editor->createSchedule(['name' => 'IT', 'number' => '2200', 'template' => 'blank']);
        $editor->saveRotation($this->line, ['user_ids' => [110, 111], 'first_handover' => '2026-11-06 17:00', 'turn_days' => 7]);
    }

    private function onDutyAt(string $local): array
    {
        $plan = (new ScheduleRepository($this->module))->plan($this->line, Employees::directory($this->core));

        return (new DutyResolver($plan, new DateTimeZone('Europe/Berlin')))->at(self::ts($local))->targets;
    }

    public function testAMemberTakesOverUntilTheNextHandover(): void
    {
        $result = TakeOver::take($this->module, $this->core, '202', '', self::ts('2026-11-11 10:00'));

        self::assertSame(TakeOver::OK, $result['result']);
        self::assertSame(self::ts('2026-11-13 17:00'), $result['until']);
        self::assertSame(['202'], $this->onDutyAt('2026-11-11 10:30'));
        // At the handover the plan takes over again: Ben's own turn.
        self::assertSame(['202'], $this->onDutyAt('2026-11-13 17:30'));
        self::assertSame(['201'], $this->onDutyAt('2026-11-11 09:59'));
        $override = (new ScheduleRepository($this->module))->overrides($this->line)[0];
        self::assertSame(['takeover', 'phone 202', '*22'], [$override['kind'], $override['created_by'], $override['note']]);
    }

    public function testWebRtcEndpointCountsAsTheSamePerson(): void
    {
        self::assertSame(TakeOver::OK, TakeOver::take($this->module, $this->core, '202-WS', '2200', self::ts('2026-11-11 10:00'))['result']);
    }

    public function testTheOneAlreadyOnDutyChangesNothing(): void
    {
        self::assertSame(TakeOver::OK, TakeOver::take($this->module, $this->core, '201', '', self::ts('2026-11-11 10:00'))['result']);
        self::assertSame([], (new ScheduleRepository($this->module))->overrides($this->line));
    }

    public function testOnlyMembersCanTakeOver(): void
    {
        self::assertSame(TakeOver::NOT_A_MEMBER, TakeOver::take($this->module, $this->core, '203', '', self::ts('2026-11-11 10:00'))['result']);
        self::assertSame(TakeOver::NOT_AN_EMPLOYEE, TakeOver::take($this->module, $this->core, 'SIP-TRUNK-1', '', self::ts('2026-11-11 10:00'))['result']);
        self::assertSame(TakeOver::NOT_A_MEMBER, TakeOver::take($this->module, $this->core, '202', '2999', self::ts('2026-11-11 10:00'))['result']);
    }

    public function testAMemberOfSeveralSchedulesMustDialTheNumber(): void
    {
        $editor = new ScheduleEditor($this->module, $this->core, 'admin', self::ts('2026-11-11 10:00'));
        $other = $editor->createSchedule(['name' => 'Night', 'number' => '2201', 'template' => 'blank']);
        $editor->saveRotation($other, ['user_ids' => [111], 'first_handover' => '2026-11-06 17:00', 'turn_days' => 7]);

        self::assertSame(TakeOver::WHICH_SCHEDULE, TakeOver::take($this->module, $this->core, '202', '', self::ts('2026-11-11 10:00'))['result']);
        self::assertSame($this->line, TakeOver::take($this->module, $this->core, '202', '2200', self::ts('2026-11-11 10:00'))['schedule_id']);
    }

    public function testAtMostAWeekWhenNothingChangesSooner(): void
    {
        $editor = new ScheduleEditor($this->module, $this->core, 'admin', self::ts('2026-11-11 10:00'));
        $quiet = $editor->createSchedule(['name' => 'Quiet', 'number' => '2202', 'template' => 'blank']);
        $editor->addOverride($quiet, ['kind' => 'cover', 'start_at' => '2026-12-01 08:00', 'end_at' => '2026-12-01 09:00', 'user_ids' => [112]]);

        $result = TakeOver::take($this->module, $this->core, '203', '2202', self::ts('2026-11-11 10:00'));
        self::assertSame(self::ts('2026-11-18 10:00'), $result['until']);
    }
}
