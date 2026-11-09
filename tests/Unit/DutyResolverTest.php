<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Modules\ModuleOnCallCalendar\Lib\DutyResolver;
use Modules\ModuleOnCallCalendar\Lib\DutySegment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Times are Europe/Berlin, which has daylight-saving changes. 2026-11-09 is
 * a Monday.
 */
final class DutyResolverTest extends TestCase
{
    private const string TZ = 'Europe/Berlin';

    private const array MEMBERS = [
        1 => ['name' => 'Anna', 'extension' => '104'],
        2 => ['name' => 'Ben', 'extension' => '105'],
        3 => ['name' => 'Carla', 'extension' => '107'],
        4 => ['name' => 'Dee', 'extension' => '108'],
        5 => ['name' => 'Gone', 'extension' => null],
    ];

    /** The proposal's "one Monday on the Service line". */
    private static function serviceLine(array $overrides = []): array
    {
        return [
            'members' => self::MEMBERS,
            'shift_types' => [
                ['id' => 10, 'start' => '08:00', 'end' => '13:00', 'weekdays' => [1, 2, 3, 4, 5], 'sort' => 1],
                ['id' => 11, 'start' => '13:00', 'end' => '17:00', 'weekdays' => [1, 2, 3, 4, 5], 'sort' => 2],
                ['id' => 12, 'start' => '17:00', 'end' => '18:00', 'weekdays' => [1, 2, 3, 4, 5], 'sort' => 3],
            ],
            'rosters' => [[
                'id' => 1, 'priority' => 1, 'valid_from' => '2026-11-02', 'valid_until' => '', 'cycle_weeks' => 1,
                'cells' => [
                    ['shift_type_id' => 10, 'week' => 1, 'weekday' => 1, 'member_id' => 1],
                    ['shift_type_id' => 11, 'week' => 1, 'weekday' => 1, 'member_id' => 2],
                    ['shift_type_id' => 11, 'week' => 1, 'weekday' => 1, 'member_id' => 4],
                ],
            ]],
            'overrides' => $overrides,
        ];
    }

    private static function at(array $plan, string $local): DutySegment
    {
        return (new DutyResolver($plan, new DateTimeZone(self::TZ)))->at(self::ts($local));
    }

    private static function ts(string $local): int
    {
        return (new DateTimeImmutable($local, new DateTimeZone(self::TZ)))->getTimestamp();
    }

    public static function serviceLineMonday(): array
    {
        return [
            'before the first shift'    => ['2026-11-09 07:30', DutyResolver::START_OUTSIDE, [], '2026-11-09 08:00'],
            'morning: Anna'              => ['2026-11-09 09:10', DutyResolver::START_ON_DUTY, ['104'], '2026-11-09 13:00'],
            'afternoon: Ben and Dee'     => ['2026-11-09 14:00', DutyResolver::START_ON_DUTY, ['105', '108'], '2026-11-09 17:00'],
            'late shift empty: gap'      => ['2026-11-09 17:30', DutyResolver::START_GAP, [], '2026-11-09 18:00'],
            'evening: outside'           => ['2026-11-09 19:00', DutyResolver::START_OUTSIDE, [], '2026-11-10 08:00'],
            'Tuesday morning cell empty' => ['2026-11-10 09:00', DutyResolver::START_GAP, [], '2026-11-10 18:00'],
            'Saturday: no shift'         => ['2026-11-14 11:00', DutyResolver::START_OUTSIDE, [], '2026-11-16 08:00'],
        ];
    }

    #[DataProvider('serviceLineMonday')]
    public function testRosterDecidesByShiftAndWeekday(string $when, string $start, array $targets, string $until): void
    {
        $segment = self::at(self::serviceLine(), $when);

        self::assertSame($start, $segment->start);
        self::assertSame($targets, $segment->targets);
        self::assertSame(self::ts($until), $segment->to);
    }

    public function testDialStringRingsEveryTargetThroughTheExtensionDialplan(): void
    {
        self::assertSame(
            'Local/105@internal-users/n&Local/108@internal-users/n',
            self::at(self::serviceLine(), '2026-11-09 14:00')->dialString()
        );
    }

    public function testCoverOverrideBeatsTheRosterAndEndsOnTime(): void
    {
        $plan = self::serviceLine([
            ['id' => 1, 'kind' => 'cover', 'start_at' => '2026-11-09 10:30', 'end_at' => '2026-11-09 12:00', 'members' => [3]],
        ]);

        $during = self::at($plan, '2026-11-09 11:00');
        self::assertSame(['107'], $during->targets);
        self::assertSame(DutyResolver::BY_OVERRIDE, $during->decidedBy);
        self::assertSame(self::ts('2026-11-09 12:00'), $during->to);
        self::assertSame(['104'], self::at($plan, '2026-11-09 12:00')->targets);
    }

    public function testNewestOverlappingOverrideWins(): void
    {
        $plan = self::serviceLine([
            ['id' => 1, 'kind' => 'cover', 'start_at' => '2026-11-09 08:00', 'end_at' => '2026-11-09 18:00', 'members' => [3]],
            ['id' => 2, 'kind' => 'cover', 'start_at' => '2026-11-09 10:00', 'end_at' => '2026-11-09 11:00', 'members' => [2]],
        ]);

        self::assertSame(['105'], self::at($plan, '2026-11-09 10:30')->targets);
        self::assertSame(['107'], self::at($plan, '2026-11-09 11:30')->targets);
    }

    public function testSendElsewhereOverride(): void
    {
        $plan = self::serviceLine([
            ['id' => 1, 'kind' => 'elsewhere', 'start_at' => '2026-11-09 08:00', 'end_at' => '2026-11-09 18:00', 'destination' => 'voicemail'],
        ]);
        $segment = self::at($plan, '2026-11-09 09:00');

        self::assertSame(DutyResolver::START_ELSEWHERE, $segment->start);
        self::assertSame('voicemail', $segment->destination);
    }

    public function testOverrideWorksOutsideCoveredHoursToo(): void
    {
        $plan = self::serviceLine([
            ['id' => 1, 'kind' => 'takeover', 'start_at' => '2026-11-09 19:00', 'end_at' => '2026-11-09 21:00', 'members' => [1]],
        ]);

        self::assertSame(['104'], self::at($plan, '2026-11-09 20:00')->targets);
    }

    public function testPeopleWithoutAnExtensionLeaveAGap(): void
    {
        $plan = self::serviceLine([
            ['id' => 1, 'kind' => 'cover', 'start_at' => '2026-11-09 08:00', 'end_at' => '2026-11-09 13:00', 'members' => [5]],
        ]);
        $segment = self::at($plan, '2026-11-09 09:00');

        self::assertSame(DutyResolver::START_GAP, $segment->start);
        self::assertSame([5], $segment->memberIds);
    }

    public function testOverlappingShiftTypeLowerInTheListWins(): void
    {
        $plan = self::serviceLine();
        $plan['shift_types'][] = ['id' => 13, 'start' => '12:00', 'end' => '13:00', 'weekdays' => [1], 'sort' => 4];
        $plan['rosters'][0]['cells'][] = ['shift_type_id' => 13, 'week' => 1, 'weekday' => 1, 'member_id' => 2];

        self::assertSame(['104'], self::at($plan, '2026-11-09 11:59')->targets);
        self::assertSame(['105'], self::at($plan, '2026-11-09 12:30')->targets);
    }

    public function testOvernightShiftBelongsToTheDayItStarts(): void
    {
        $plan = [
            'members' => self::MEMBERS,
            'shift_types' => [['id' => 1, 'start' => '22:00', 'end' => '06:00', 'weekdays' => [1], 'sort' => 1]],
            'rosters' => [['id' => 1, 'priority' => 1, 'valid_from' => '2026-11-02', 'cycle_weeks' => 1,
                'cells' => [['shift_type_id' => 1, 'week' => 1, 'weekday' => 1, 'member_id' => 1]]]],
        ];

        self::assertSame(DutyResolver::START_OUTSIDE, self::at($plan, '2026-11-09 03:00')->start);
        self::assertSame(['104'], self::at($plan, '2026-11-09 23:00')->targets);
        self::assertSame(['104'], self::at($plan, '2026-11-10 03:00')->targets);
        self::assertSame(self::ts('2026-11-10 06:00'), self::at($plan, '2026-11-10 03:00')->to);
    }

    public function testTwentyFourHourShiftAndMidnightEnd(): void
    {
        $plan = [
            'members' => self::MEMBERS,
            'shift_types' => [
                ['id' => 1, 'start' => '00:00', 'end' => '00:00', 'weekdays' => [6], 'sort' => 1],
                ['id' => 2, 'start' => '18:00', 'end' => '24:00', 'weekdays' => [5], 'sort' => 2],
            ],
            'rosters' => [['id' => 1, 'priority' => 1, 'valid_from' => '2026-11-02', 'cycle_weeks' => 1, 'cells' => [
                ['shift_type_id' => 1, 'week' => 1, 'weekday' => 6, 'member_id' => 1],
                ['shift_type_id' => 2, 'week' => 1, 'weekday' => 5, 'member_id' => 2],
            ]]],
        ];

        self::assertSame(['105'], self::at($plan, '2026-11-13 23:59')->targets);
        self::assertSame(['104'], self::at($plan, '2026-11-14 00:00')->targets);
        self::assertSame(self::ts('2026-11-15 00:00'), self::at($plan, '2026-11-14 12:00')->to);
    }

    public function testRosterCycleAlternatesWeeks(): void
    {
        $plan = [
            'members' => self::MEMBERS,
            'shift_types' => [['id' => 1, 'start' => '09:00', 'end' => '17:00', 'weekdays' => [6], 'sort' => 1]],
            // Starts on a Wednesday: week 1 is that Monday-to-Sunday week.
            'rosters' => [['id' => 1, 'priority' => 1, 'valid_from' => '2026-11-04', 'cycle_weeks' => 2, 'cells' => [
                ['shift_type_id' => 1, 'week' => 1, 'weekday' => 6, 'member_id' => 1],
                ['shift_type_id' => 1, 'week' => 2, 'weekday' => 6, 'member_id' => 2],
            ]]],
        ];

        self::assertSame(['104'], self::at($plan, '2026-11-07 10:00')->targets);
        self::assertSame(['105'], self::at($plan, '2026-11-14 10:00')->targets);
        self::assertSame(['104'], self::at($plan, '2026-11-21 10:00')->targets);
    }

    public function testRosterValidityDates(): void
    {
        $plan = self::serviceLine();
        $plan['rosters'][0]['valid_from'] = '2026-11-09';
        $plan['rosters'][0]['valid_until'] = '2026-11-15';

        self::assertSame(DutyResolver::START_OUTSIDE, self::at($plan, '2026-11-02 09:00')->start);
        self::assertSame(['104'], self::at($plan, '2026-11-09 09:00')->targets);
        self::assertSame(DutyResolver::START_OUTSIDE, self::at($plan, '2026-11-16 09:00')->start);
    }

    private static function itEmergencies(): array
    {
        return [
            'members' => self::MEMBERS,
            'rotations' => [[
                'id' => 1, 'priority' => 1, 'members' => [1, 2, 3], 'first_handover' => '2026-11-06 17:00', 'turn_days' => 7,
                'active_hours' => [
                    ['weekdays' => [1, 2, 3, 4, 5, 6, 7], 'start' => '18:00', 'end' => '08:00'],
                    ['weekdays' => [6, 7], 'start' => '00:00', 'end' => '00:00'],
                ],
            ]],
        ];
    }

    public static function rotationMoments(): array
    {
        return [
            'before the first handover' => ['2026-11-06 16:00', DutyResolver::START_OUTSIDE, []],
            'first turn, Wednesday night' => ['2026-11-11 02:10', DutyResolver::START_ON_DUTY, ['104']],
            'first turn, weekday daytime' => ['2026-11-11 12:00', DutyResolver::START_OUTSIDE, []],
            'first turn, Saturday midday' => ['2026-11-07 12:00', DutyResolver::START_ON_DUTY, ['104']],
            'handover, before active hours' => ['2026-11-13 17:30', DutyResolver::START_OUTSIDE, []],
            'second turn starts' => ['2026-11-13 18:30', DutyResolver::START_ON_DUTY, ['105']],
            'Sunday night into Monday' => ['2026-11-16 03:00', DutyResolver::START_ON_DUTY, ['105']],
            'third turn' => ['2026-11-20 18:30', DutyResolver::START_ON_DUTY, ['107']],
            'back to the first person' => ['2026-11-27 18:30', DutyResolver::START_ON_DUTY, ['104']],
        ];
    }

    #[DataProvider('rotationMoments')]
    public function testRotationTakesTurnsInsideActiveHours(string $when, string $start, array $targets): void
    {
        $segment = self::at(self::itEmergencies(), $when);

        self::assertSame($start, $segment->start);
        self::assertSame($targets, $segment->targets);
    }

    public function testRotationHandoverWithoutActiveHoursIsExact(): void
    {
        $plan = self::itEmergencies();
        $plan['rotations'][0]['active_hours'] = [];

        $segment = self::at($plan, '2026-11-13 16:59');
        self::assertSame(['104'], $segment->targets);
        self::assertSame(self::ts('2026-11-13 17:00'), $segment->to);
        self::assertSame(['105'], self::at($plan, '2026-11-13 17:00')->targets);
    }

    public function testRotationEndsAfterItsLastDayAndHandlesLongTurns(): void
    {
        $plan = self::itEmergencies();
        $plan['rotations'][0]['active_hours'] = [];
        $plan['rotations'][0]['turn_days'] = 10;
        $plan['rotations'][0]['valid_until'] = '2026-11-30';

        self::assertSame(['104'], self::at($plan, '2026-11-16 16:59')->targets);
        self::assertSame(['105'], self::at($plan, '2026-11-16 17:00')->targets);
        self::assertSame(['107'], self::at($plan, '2026-11-26 17:00')->targets);
        self::assertSame(['107'], self::at($plan, '2026-11-30 23:59')->targets);
        self::assertSame(DutyResolver::START_OUTSIDE, self::at($plan, '2026-12-01 00:00')->start);
    }

    public function testHandoverInTheSpringForwardGapHappensWhenTheClockJumps(): void
    {
        $plan = self::itEmergencies();
        $plan['rotations'][0]['active_hours'] = [];
        $plan['rotations'][0]['first_handover'] = '2026-03-22 02:30';

        // 29 March 2026 02:30 doesn't exist in Berlin; the handover is at 03:30, right after the jump.
        self::assertSame(['104'], self::at($plan, '2026-03-29 01:59')->targets);
        self::assertSame(['105'], self::at($plan, '2026-03-29 03:30')->targets);
    }

    public function testNothingChangingForTwoWeeksStillExpires(): void
    {
        $plan = self::itEmergencies();
        $plan['rotations'][0]['active_hours'] = [];
        $plan['rotations'][0]['members'] = [1];
        $segment = self::at($plan, '2026-11-10 12:00');

        self::assertSame(['104'], $segment->targets);
        self::assertSame(self::ts('2026-11-10 12:00') + 14 * 86400, $segment->to);
    }

    public function testFirstRuleThatCoversWinsEvenWithAnEmptyCell(): void
    {
        $plan = self::serviceLine() + ['rotations' => [[
            'id' => 2, 'priority' => 2, 'members' => [3], 'first_handover' => '2026-11-01 00:00', 'turn_days' => 7,
        ]]];

        // The roster covers Tuesday 09:00 but has nobody: a gap, not the rotation.
        self::assertSame(DutyResolver::START_GAP, self::at($plan, '2026-11-10 09:00')->start);
        // Outside the roster's shifts, the rotation decides.
        self::assertSame(['107'], self::at($plan, '2026-11-10 20:00')->targets);

        $plan['rotations'][0]['priority'] = 0;
        self::assertSame(['107'], self::at($plan, '2026-11-09 09:00')->targets);
    }

    public function testDaylightSavingChangesShiftLengthsNotWallClockTimes(): void
    {
        $plan = [
            'members' => self::MEMBERS,
            'shift_types' => [['id' => 1, 'start' => '22:00', 'end' => '06:00', 'weekdays' => [6], 'sort' => 1]],
            'rosters' => [['id' => 1, 'priority' => 1, 'valid_from' => '2026-03-02', 'cycle_weeks' => 1,
                'cells' => [['shift_type_id' => 1, 'week' => 1, 'weekday' => 6, 'member_id' => 1]]]],
        ];
        $resolver = new DutyResolver($plan, new DateTimeZone(self::TZ));

        // Spring forward (29 March 2026): the night is 7 hours.
        $spring = $resolver->at(self::ts('2026-03-28 23:00'));
        self::assertSame(7 * 3600, $spring->to - self::ts('2026-03-28 22:00'));
        // Fall back (25 October 2026): the night is 9 hours.
        $autumn = $resolver->at(self::ts('2026-10-24 23:00'));
        self::assertSame(9 * 3600, $autumn->to - self::ts('2026-10-24 22:00'));
        self::assertSame(self::ts('2026-10-25 06:00'), $autumn->to);
    }

    public function testSegmentsCoverTheWholeRangeWithoutGapsOrRepeats(): void
    {
        $resolver = new DutyResolver(self::serviceLine([
            ['id' => 1, 'kind' => 'cover', 'start_at' => '2026-11-09 10:30', 'end_at' => '2026-11-09 12:00', 'members' => [3]],
        ]), new DateTimeZone(self::TZ));
        $from = self::ts('2026-11-09 00:00');
        $to = self::ts('2026-11-10 00:00');
        $segments = $resolver->segments($from, $to);

        self::assertSame($from, $segments[0]->from);
        self::assertSame($to, $segments[count($segments) - 1]->to);
        for ($i = 1, $n = count($segments); $i < $n; $i++) {
            self::assertSame($segments[$i - 1]->to, $segments[$i]->from);
            self::assertFalse($segments[$i - 1]->sameDecisionAs($segments[$i]));
        }
        self::assertSame(
            ['out', '1', '1', '1', '1', '2', 'out'],
            array_map(static fn (DutySegment $s): string => $s->start, $segments)
        );
    }

    public function testInvalidPlanEntriesAreIgnored(): void
    {
        $plan = self::serviceLine([
            ['id' => 1, 'kind' => 'cover', 'start_at' => 'tomorrow', 'end_at' => '2026-11-09 12:00', 'members' => [3]],
            ['id' => 2, 'kind' => 'cover', 'start_at' => '2026-11-09 12:00', 'end_at' => '2026-11-09 11:00', 'members' => [3]],
        ]);
        $plan['shift_types'][] = ['id' => 99, 'start' => '25:00', 'end' => '26:00', 'weekdays' => [1], 'sort' => 9];
        $plan['rosters'][] = ['id' => 2, 'priority' => 0, 'valid_from' => 'never', 'cycle_weeks' => 1, 'cells' => []];

        self::assertSame(['104'], self::at($plan, '2026-11-09 11:00')->targets);
    }

    public function testCalendarHelpers(): void
    {
        self::assertSame(1, DutyResolver::isoWeekday((int)DutyResolver::dayNumber('2026-11-09')));
        self::assertSame(7, DutyResolver::isoWeekday((int)DutyResolver::dayNumber('2026-11-15')));
        self::assertSame('2026-11-09', DutyResolver::dateOf((int)DutyResolver::dayNumber('2026-11-09')));
        self::assertNull(DutyResolver::dayNumber('2026-02-30'));
        self::assertSame(1440, DutyResolver::minutes('24:00'));
        self::assertNull(DutyResolver::minutes('24:01'));
        self::assertNull(DutyResolver::minutes('9:00'));
    }
}
