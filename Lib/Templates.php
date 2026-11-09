<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Lib;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The defaults a new schedule starts from. There is one kind of schedule; a
 * template only fills in values, and every one can be changed afterwards.
 *
 * - shift: a weekly roster with Morning and Afternoon shifts, Monday to
 *   Friday; staff at their desks answer fast, so 20 s ring time.
 * - oncall: a rotation, active in the evenings, at night and at weekends;
 *   25 s ring time, because someone on call at home needs longer.
 * - blank: nothing filled in.
 */
final class Templates
{
    /** The on-call template's active hours: every evening into the next morning, and all weekend. */
    public const array ONCALL_ACTIVE_HOURS = [
        ['weekdays' => [1, 2, 3, 4, 5, 6, 7], 'start' => '18:00', 'end' => '08:00'],
        ['weekdays' => [6, 7], 'start' => '00:00', 'end' => '00:00'],
    ];

    /** @return array{ring_seconds: int, backup_ring_seconds: int} */
    public static function schedule(string $template): array
    {
        return match ($template) {
            'oncall' => ['ring_seconds' => 25, 'backup_ring_seconds' => 25],
            default  => ['ring_seconds' => 20, 'backup_ring_seconds' => 20],
        };
    }

    /**
     * @param array{0?: string, 1?: string} $names the two shift names in the admin's language
     * @return list<array{name: string, start: string, end: string, weekdays: list<int>}>
     */
    public static function shiftTypes(string $template, array $names = []): array
    {
        if ($template !== 'shift') {
            return [];
        }

        return [
            ['name' => $names[0] ?? 'Morning', 'start' => '08:00', 'end' => '13:00', 'weekdays' => [1, 2, 3, 4, 5]],
            ['name' => $names[1] ?? 'Afternoon', 'start' => '13:00', 'end' => '18:00', 'weekdays' => [1, 2, 3, 4, 5]],
        ];
    }

    /** The Monday of the current week (PBX time), as "Y-m-d": where a new roster starts. */
    public static function weekStart(int $now, string $timezone): string
    {
        $today = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone($timezone));

        return $today->modify('monday this week')->format('Y-m-d');
    }
}
