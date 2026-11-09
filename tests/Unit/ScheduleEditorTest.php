<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Modules\ModuleOnCallCalendar\Lib\DutyResolver;
use Modules\ModuleOnCallCalendar\Lib\DutySegment;
use Modules\ModuleOnCallCalendar\Lib\EditorException;
use Modules\ModuleOnCallCalendar\Lib\Employees;
use Modules\ModuleOnCallCalendar\Lib\ScheduleEditor;
use Modules\ModuleOnCallCalendar\Lib\ScheduleRepository;
use Modules\ModuleOnCallCalendar\Tests\Unit\Support\TestDatabases;
use PDO;
use PHPUnit\Framework\TestCase;

final class ScheduleEditorTest extends TestCase
{
    /** Wednesday 2026-11-11 10:00, Berlin. */
    private const int NOW = 1794387600;

    private const array STAFF = [110 => ['Anna', '201'], 111 => ['Ben', '202'], 112 => ['Carla', '203'], 113 => ['Left', null]];

    private PDO $module;

    private PDO $core;

    private ScheduleEditor $editor;

    protected function setUp(): void
    {
        $this->module = TestDatabases::module();
        $this->core = TestDatabases::core(self::STAFF);
        $this->editor = new ScheduleEditor($this->module, $this->core, 'admin', self::NOW);
    }

    private function repository(): ScheduleRepository
    {
        return new ScheduleRepository($this->module);
    }

    private function expectRefusal(string $key): void
    {
        $this->expectException(EditorException::class);
        $this->expectExceptionMessageMatches('/^' . preg_quote($key, '/') . '\b/');
    }

    private function shiftSchedule(): int
    {
        return $this->editor->createSchedule(['name' => 'Service line', 'number' => '2202', 'template' => 'shift',
            'backup_type' => 'queue', 'backup_number' => '2000']);
    }

    private function resolveAt(int $scheduleId, string $local): DutySegment
    {
        $tz = new DateTimeZone('Europe/Berlin');
        $plan = $this->repository()->plan($scheduleId, Employees::directory($this->core));

        return (new DutyResolver($plan, $tz))->at((new DateTimeImmutable($local, $tz))->getTimestamp());
    }

    public function testNowIsWednesdayMorning(): void
    {
        self::assertSame('2026-11-11 10:00', (new DateTimeImmutable('@' . self::NOW))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d H:i'));
    }

    public function testShiftTemplateCreatesShiftsARosterAndTheChain(): void
    {
        $id = $this->shiftSchedule();
        $schedule = $this->repository()->schedule($id);

        self::assertSame(['shift', 20, 'queue', '2000', 'voicemail'], [$schedule['template'], $schedule['ring_seconds'],
            $schedule['backup_type'], $schedule['backup_number'], $schedule['final_number']]);
        self::assertSame(['Morning', 'Afternoon'], array_column($this->repository()->shiftTypes($id), 'name'));
        $rosters = $this->repository()->rosters($id);
        self::assertCount(1, $rosters);
        self::assertSame('2026-11-09', $rosters[0]['valid_from']);
        self::assertSame('schedule.create', $this->module->query('SELECT action FROM m_Audit')->fetchColumn());
    }

    public function testShiftNamesCanBeTranslated(): void
    {
        $id = $this->editor->createSchedule(['name' => 'Линия', 'number' => '2202', 'template' => 'shift', 'shift_names' => ['Утро', 'День']]);

        self::assertSame(['Утро', 'День'], array_column($this->repository()->shiftTypes($id), 'name'));
    }

    public function testOncallTemplateWithRotation(): void
    {
        $id = $this->editor->createSchedule(['name' => 'IT emergencies', 'number' => '2200', 'template' => 'oncall',
            'backup_type' => 'member', 'backup_user_id' => 112,
            'rotation' => ['user_ids' => [110, 111], 'first_handover' => '2026-11-06 17:00', 'turn_days' => 7,
                'active_hours' => \Modules\ModuleOnCallCalendar\Lib\Templates::ONCALL_ACTIVE_HOURS]]);

        self::assertSame(25, $this->repository()->schedule($id)['ring_seconds']);
        self::assertSame(['201'], $this->resolveAt($id, '2026-11-11 23:00')->targets);
        self::assertSame(['202'], $this->resolveAt($id, '2026-11-13 19:00')->targets);
        // The backup person became a member too.
        self::assertCount(3, $this->repository()->members($id, Employees::directory($this->core)));
    }

    public function testNumberMustBeFree(): void
    {
        $this->shiftSchedule();
        $this->expectRefusal('occ_ErrNumberTaken');
        $this->editor->createSchedule(['name' => 'Again', 'number' => '2202', 'template' => 'blank']);
    }

    public function testNumberOfAnotherExtensionIsRefused(): void
    {
        $this->expectRefusal('occ_ErrNumberTaken');
        $this->editor->createSchedule(['name' => 'Clash', 'number' => '201', 'template' => 'blank']);
    }

    public function testAnotherModulesNumberIsTaken(): void
    {
        $this->core->exec("INSERT INTO m_Extensions (number, type) VALUES ('7000', 'MODULES')");

        $this->expectRefusal('occ_ErrNumberTaken');
        $this->editor->createSchedule(['name' => 'Clash', 'number' => '7000', 'template' => 'blank']);
    }

    public function testNamesThatWouldBreakTheCorePickersAreRefused(): void
    {
        $this->expectRefusal('occ_ErrName');
        $this->editor->createSchedule(['name' => 'Support <L1>', 'number' => '2202', 'template' => 'blank']);
    }

    public function testDestinationDeletedInTheCoreDoesNotBlockOtherChanges(): void
    {
        $id = $this->shiftSchedule();
        $this->editor->updateSchedule($id, ['final_number' => '2003']);
        $this->core->exec("DELETE FROM m_Extensions WHERE number IN ('2003', '2000')");

        $this->editor->updateSchedule($id, ['name' => 'Renamed', 'enabled' => false]);
        self::assertSame('Renamed', $this->repository()->schedule($id)['name']);
        // A new choice is checked as usual.
        $this->expectRefusal('occ_ErrDestination');
        $this->editor->updateSchedule($id, ['outside_number' => '2999']);
    }

    public function testACircleOfSchedulesIsRefused(): void
    {
        $this->core->exec("INSERT INTO m_Extensions (number, type) VALUES ('2202', 'MODULES'), ('2203', 'MODULES'), ('2204', 'MODULES')");
        $a = $this->editor->createSchedule(['name' => 'A', 'number' => '2210', 'template' => 'blank']);
        $this->core->exec("INSERT INTO m_Extensions (number, type) VALUES ('2210', 'MODULES')");
        $b = $this->editor->createSchedule(['name' => 'B', 'number' => '2211', 'template' => 'blank', 'outside_number' => '2210']);
        $this->core->exec("INSERT INTO m_Extensions (number, type) VALUES ('2211', 'MODULES')");

        $this->expectRefusal('occ_ErrDestinationCircle');
        $this->editor->updateSchedule($a, ['final_number' => '2211']);
    }

    public function testSuggestedNumberSkipsTakenOnes(): void
    {
        $this->core->exec("INSERT INTO m_Extensions (number, type) VALUES ('2200', 'QUEUE'), ('2201', 'MODULES')");

        self::assertSame('2202', $this->editor->suggestNumber());
    }

    public function testBackupQueueMustBeAQueue(): void
    {
        $this->expectRefusal('occ_ErrBackupQueue');
        $this->editor->createSchedule(['name' => 'X', 'number' => '2202', 'template' => 'blank', 'backup_type' => 'queue', 'backup_number' => '2003']);
    }

    public function testFinalDestinationMustExistAndNotBeItself(): void
    {
        $id = $this->shiftSchedule();
        try {
            $this->editor->updateSchedule($id, ['final_number' => '2999']);
            self::fail('Unknown number accepted');
        } catch (EditorException $e) {
            self::assertSame('occ_ErrDestination', $e->key);
        }
        $this->expectRefusal('occ_ErrDestination');
        $this->editor->updateSchedule($id, ['final_number' => '800']);
    }

    public function testUpdateKeepsUnchangedChainFieldsAndAudits(): void
    {
        $id = $this->shiftSchedule();
        $this->editor->updateSchedule($id, ['ring_seconds' => 30, 'final_number' => '2003', 'greeting_sound_id' => 5]);
        $schedule = $this->repository()->schedule($id);

        self::assertSame([30, 'queue', '2000', '2003', 5], [$schedule['ring_seconds'], $schedule['backup_type'],
            $schedule['backup_number'], $schedule['final_number'], $schedule['greeting_sound_id']]);
        $audit = $this->module->query("SELECT before_json, after_json FROM m_Audit WHERE action = 'schedule.update'")->fetch();
        self::assertSame(20, json_decode($audit['before_json'], true)['ring_seconds']);
        self::assertSame(30, json_decode($audit['after_json'], true)['ring_seconds']);
    }

    public function testRingTimeLimits(): void
    {
        $id = $this->shiftSchedule();
        $this->expectRefusal('occ_ErrRingTime');
        $this->editor->updateSchedule($id, ['ring_seconds' => 2]);
    }

    public function testUnknownSoundIsRefused(): void
    {
        $id = $this->shiftSchedule();
        $this->expectRefusal('occ_ErrSound');
        $this->editor->updateSchedule($id, ['greeting_sound_id' => 99]);
    }

    public function testRosterCellsRingTogetherAndCanBeEmptied(): void
    {
        $id = $this->shiftSchedule();
        $roster = $this->repository()->rosters($id)[0]['id'];
        [$morning] = array_column($this->repository()->shiftTypes($id), 'id');

        $this->editor->setCell($id, $roster, $morning, 1, 3, [110, 111]);
        self::assertSame(['201', '202'], $this->resolveAt($id, '2026-11-11 09:00')->targets);

        $this->editor->setCell($id, $roster, $morning, 1, 3, []);
        self::assertSame(DutyResolver::START_GAP, $this->resolveAt($id, '2026-11-11 09:00')->start);
    }

    public function testCellOnADayTheShiftDoesNotRunIsRefused(): void
    {
        $id = $this->shiftSchedule();
        $roster = $this->repository()->rosters($id)[0]['id'];
        [$morning] = array_column($this->repository()->shiftTypes($id), 'id');

        $this->expectRefusal('occ_ErrInvalid');
        $this->editor->setCell($id, $roster, $morning, 1, 6, [110]);
    }

    public function testPeopleWithoutAnExtensionCannotBePicked(): void
    {
        $id = $this->shiftSchedule();
        $roster = $this->repository()->rosters($id)[0]['id'];
        [$morning] = array_column($this->repository()->shiftTypes($id), 'id');

        $this->expectRefusal('occ_ErrNoExtension');
        $this->editor->setCell($id, $roster, $morning, 1, 1, [113]);
    }

    public function testShorterCycleDropsLaterWeeks(): void
    {
        $id = $this->shiftSchedule();
        $roster = $this->repository()->rosters($id)[0];
        [$morning] = array_column($this->repository()->shiftTypes($id), 'id');
        $this->editor->saveRoster($id, ['id' => $roster['id'], 'valid_from' => '2026-11-09', 'cycle_weeks' => 2]);
        $this->editor->setCell($id, $roster['id'], $morning, 2, 1, [111]);
        $this->editor->saveRoster($id, ['id' => $roster['id'], 'valid_from' => '2026-11-09', 'cycle_weeks' => 1]);

        self::assertSame([], $this->repository()->rosters($id)[0]['cells']);
    }

    public function testShiftTypeOrderDecidesOverlaps(): void
    {
        $id = $this->shiftSchedule();
        $roster = $this->repository()->rosters($id)[0]['id'];
        [$morning] = array_column($this->repository()->shiftTypes($id), 'id');
        $lunch = $this->editor->saveShiftType($id, ['name' => 'Lunch', 'start' => '12:00', 'end' => '13:00', 'weekdays' => [3]]);
        $this->editor->setCell($id, $roster, $morning, 1, 3, [110]);
        $this->editor->setCell($id, $roster, $lunch, 1, 3, [111]);

        self::assertSame(['202'], $this->resolveAt($id, '2026-11-11 12:30')->targets);

        $ids = array_column($this->repository()->shiftTypes($id), 'id');
        $this->editor->orderShiftTypes($id, [$lunch, ...array_values(array_diff($ids, [$lunch]))]);
        self::assertSame(['201'], $this->resolveAt($id, '2026-11-11 12:30')->targets);
    }

    public function testDeletingAShiftTypeRemovesItsCells(): void
    {
        $id = $this->shiftSchedule();
        $roster = $this->repository()->rosters($id)[0]['id'];
        [$morning] = array_column($this->repository()->shiftTypes($id), 'id');
        $this->editor->setCell($id, $roster, $morning, 1, 3, [110]);
        $this->editor->deleteShiftType($id, $morning);

        self::assertSame([], $this->repository()->rosters($id)[0]['cells']);
    }

    public function testRemovingAWeekdayFromAShiftClearsThatDaysCells(): void
    {
        $id = $this->shiftSchedule();
        $roster = $this->repository()->rosters($id)[0]['id'];
        [$morning] = array_column($this->repository()->shiftTypes($id), 'id');
        $this->editor->setCell($id, $roster, $morning, 1, 1, [110]);
        $this->editor->setCell($id, $roster, $morning, 1, 3, [111]);
        $this->editor->saveShiftType($id, ['id' => $morning, 'name' => 'Morning', 'start' => '08:00', 'end' => '13:00', 'weekdays' => [2, 3, 4, 5]]);

        self::assertSame([3], array_column($this->repository()->rosters($id)[0]['cells'], 'weekday'));
        // Anna is planned nowhere now, so she can be removed.
        $anna = array_values(array_filter($this->repository()->members($id, []), static fn (array $m): bool => $m['user_id'] === 110))[0];
        $this->editor->removeMember($id, $anna['id']);
        self::assertCount(1, $this->repository()->members($id, []));
    }

    public function testShiftTypeValidation(): void
    {
        $id = $this->shiftSchedule();
        $this->expectRefusal('occ_ErrWeekdays');
        $this->editor->saveShiftType($id, ['name' => 'Night', 'start' => '22:00', 'end' => '06:00', 'weekdays' => [0, 8]]);
    }

    public function testCoverOverrideAndSwap(): void
    {
        $id = $this->shiftSchedule();
        $roster = $this->repository()->rosters($id)[0]['id'];
        [$morning, $afternoon] = array_column($this->repository()->shiftTypes($id), 'id');
        $this->editor->setCell($id, $roster, $morning, 1, 3, [110]);
        $this->editor->setCell($id, $roster, $afternoon, 1, 3, [111]);

        $this->editor->addOverride($id, ['kind' => 'cover', 'start_at' => '2026-11-11 10:30', 'end_at' => '2026-11-11 12:00', 'user_ids' => [112]]);
        self::assertSame(['203'], $this->resolveAt($id, '2026-11-11 11:00')->targets);

        [$a] = $this->editor->addSwap($id, ['user_a' => 110, 'start_a' => '2026-11-11 08:00', 'end_a' => '2026-11-11 10:30',
            'user_b' => 111, 'start_b' => '2026-11-11 13:00', 'end_b' => '2026-11-11 18:00']);
        self::assertSame(['202'], $this->resolveAt($id, '2026-11-11 09:00')->targets);
        self::assertSame(['201'], $this->resolveAt($id, '2026-11-11 14:00')->targets);

        // Deleting one half of a swap deletes both.
        $this->editor->deleteOverride($id, $a);
        self::assertSame(['201'], $this->resolveAt($id, '2026-11-11 09:00')->targets);
        self::assertSame(['202'], $this->resolveAt($id, '2026-11-11 14:00')->targets);
    }

    public function testSwapOfOverlappingShiftsIsRefused(): void
    {
        $id = $this->shiftSchedule();
        $this->expectRefusal('occ_ErrSwapOverlap');
        $this->editor->addSwap($id, ['user_a' => 110, 'start_a' => '2026-11-11 08:00', 'end_a' => '2026-11-11 13:00',
            'user_b' => 111, 'start_b' => '2026-11-11 08:00', 'end_b' => '2026-11-11 13:00']);
    }

    public function testOverrideOfAPersonWhoLeftCanStillBeMoved(): void
    {
        $id = $this->shiftSchedule();
        $o = $this->editor->addOverride($id, ['kind' => 'cover', 'start_at' => '2026-11-11 19:00', 'end_at' => '2026-11-11 20:00', 'user_ids' => [112]]);
        $this->core->exec("DELETE FROM m_Extensions WHERE userid = 112");
        $this->editor->updateOverride($id, $o, ['start_at' => '2026-11-11 20:00', 'end_at' => '2026-11-11 21:00']);

        self::assertSame('2026-11-11 20:00', $this->repository()->overrides($id)[0]['start_at']);
        // Choosing people again is checked as usual.
        $this->expectRefusal('occ_ErrNoExtension');
        $this->editor->updateOverride($id, $o, ['user_ids' => [112]]);
    }

    public function testOverrideCanBeMoved(): void
    {
        $id = $this->shiftSchedule();
        $o = $this->editor->addOverride($id, ['kind' => 'takeover', 'start_at' => '2026-11-11 19:00', 'end_at' => '2026-11-11 20:00', 'user_ids' => [112], 'note' => 'Event']);
        $this->editor->updateOverride($id, $o, ['start_at' => '2026-11-11 20:00', 'end_at' => '2026-11-11 22:00']);
        $override = $this->repository()->overrides($id)[0];

        self::assertSame(['2026-11-11 20:00', '2026-11-11 22:00', 'takeover', 'Event'], [$override['start_at'], $override['end_at'], $override['kind'], $override['note']]);
        self::assertSame(['203'], $this->resolveAt($id, '2026-11-11 21:00')->targets);
    }

    public function testSendElsewhereOnlyToTheScheduleOwnDestinations(): void
    {
        $id = $this->shiftSchedule();
        $this->editor->addOverride($id, ['kind' => 'elsewhere', 'start_at' => '2026-11-14 08:00', 'end_at' => '2026-11-14 18:00', 'destination' => '2000']);
        self::assertSame('2000', $this->resolveAt($id, '2026-11-14 09:00')->destination);

        $this->expectRefusal('occ_ErrElsewhere');
        $this->editor->addOverride($id, ['kind' => 'elsewhere', 'start_at' => '2026-11-14 08:00', 'end_at' => '2026-11-14 18:00', 'destination' => '2003']);
    }

    public function testOverrideTimesMustBeInOrder(): void
    {
        $id = $this->shiftSchedule();
        $this->expectRefusal('occ_ErrTimeOrder');
        $this->editor->addOverride($id, ['kind' => 'cover', 'start_at' => '2026-11-11 12:00', 'end_at' => '2026-11-11 12:00', 'user_ids' => [110]]);
    }

    public function testRuleOrder(): void
    {
        $id = $this->shiftSchedule();
        $roster = $this->repository()->rosters($id)[0]['id'];
        [$morning] = array_column($this->repository()->shiftTypes($id), 'id');
        $this->editor->setCell($id, $roster, $morning, 1, 3, [110]);
        $rotation = $this->editor->saveRotation($id, ['user_ids' => [112], 'first_handover' => '2026-11-01 00:00', 'turn_days' => 7]);

        self::assertSame(['201'], $this->resolveAt($id, '2026-11-11 09:00')->targets);
        $this->editor->orderRules($id, [['type' => 'rotation', 'id' => $rotation], ['type' => 'roster', 'id' => $roster]]);
        self::assertSame(['203'], $this->resolveAt($id, '2026-11-11 09:00')->targets);

        $this->expectRefusal('occ_ErrInvalid');
        $this->editor->orderRules($id, [['type' => 'rotation', 'id' => $rotation]]);
    }

    public function testMemberInUseCannotBeRemoved(): void
    {
        $id = $this->shiftSchedule();
        $roster = $this->repository()->rosters($id)[0]['id'];
        [$morning] = array_column($this->repository()->shiftTypes($id), 'id');
        $this->editor->setCell($id, $roster, $morning, 1, 3, [110]);
        $member = $this->repository()->members($id, [])[0]['id'];

        try {
            $this->editor->removeMember($id, $member);
            self::fail('Member in use was removed');
        } catch (EditorException $e) {
            self::assertSame('occ_ErrMemberInUse', $e->key);
        }
        $this->editor->setCell($id, $roster, $morning, 1, 3, []);
        $this->editor->removeMember($id, $member);
        self::assertSame([], $this->repository()->members($id, []));
    }

    public function testRowsOfAnotherScheduleCannotBeChanged(): void
    {
        $first = $this->shiftSchedule();
        $second = $this->editor->createSchedule(['name' => 'Other', 'number' => '2203', 'template' => 'shift']);
        $roster = $this->repository()->rosters($first)[0]['id'];

        $this->expectRefusal('occ_ErrNotFound');
        $this->editor->deleteRoster($second, $roster);
    }

    public function testDeleteScheduleRemovesEverything(): void
    {
        $id = $this->shiftSchedule();
        $this->editor->addOverride($id, ['kind' => 'cover', 'start_at' => '2026-11-11 10:30', 'end_at' => '2026-11-11 12:00', 'user_ids' => [112]]);
        $this->editor->deleteSchedule($id);

        foreach (['m_Schedules', 'm_ShiftTypes', 'm_Rosters', 'm_Overrides', 'm_Members'] as $table) {
            self::assertSame(0, (int)$this->module->query("SELECT COUNT(*) FROM $table")->fetchColumn(), $table);
        }
        self::assertSame('schedule.delete', $this->module->query('SELECT action FROM m_Audit ORDER BY id DESC')->fetchColumn());
    }

    public function testFailedChangeLeavesNothingBehind(): void
    {
        $id = $this->shiftSchedule();
        try {
            $this->editor->saveRotation($id, ['user_ids' => [110], 'first_handover' => 'soon', 'turn_days' => 7]);
        } catch (EditorException) {
        }

        self::assertSame([], $this->repository()->rotations($id));
        self::assertCount(0, $this->repository()->members($id, []));
    }
}
