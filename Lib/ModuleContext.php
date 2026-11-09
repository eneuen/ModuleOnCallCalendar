<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Lib;

use DateTimeZone;
use MikoPBX\Modules\PbxExtensionUtils;
use PDO;
use Phalcon\Di\Di;
use Throwable;

/**
 * Where the module's data lives on the PBX, and the few PBX facts the logic
 * needs (time zone, employees). Used by the worker, the AGI fallback and the
 * module page; the logic classes themselves get everything passed in.
 */
final class ModuleContext
{
    /** The core's own default when PBXTimezone is not set. */
    private const string DEFAULT_TIMEZONE = 'Europe/Moscow';

    public static function moduleDbFile(): string
    {
        return PbxExtensionUtils::getModuleDir(OnCallCalendarConf::MODULE_ID) . '/db/module.db';
    }

    public static function coreDbFile(): string
    {
        return (string)Di::getDefault()->getShared('config')->path('database.dbfile');
    }

    /**
     * The PBX's time zone, read afresh each time: long-running workers keep
     * the zone they started with, so date_default_timezone_get() can be stale.
     */
    public static function timezone(PDO $core): DateTimeZone
    {
        $name = Databases::coreSetting($core, 'PBXTimezone', self::DEFAULT_TIMEZONE);
        try {
            return new DateTimeZone($name);
        } catch (Throwable) {
            return new DateTimeZone(self::DEFAULT_TIMEZONE);
        }
    }

    /**
     * The AstDB values for every enabled schedule (or only $only), right now.
     *
     * @return array<int, array{start: string, dial: string, goto: string, until: string}>
     */
    public static function currentValues(PDO $module, PDO $core, int $now, ?int $only = null): array
    {
        $repository = new ScheduleRepository($module);
        $directory = Employees::directory($core);
        $schedules = $repository->schedules();
        if ($only !== null) {
            $schedules = array_values(array_filter($schedules, static fn (array $s): bool => (int)$s['id'] === $only));
        }

        return DutyPublisher::values(
            $schedules,
            static fn (int $id, string $since): array => $repository->plan($id, $directory, $since),
            self::timezone($core),
            $now
        );
    }
}
