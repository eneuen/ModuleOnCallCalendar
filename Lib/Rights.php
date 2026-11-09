<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Lib;

/**
 * The module page's actions, grouped into the three rights an access group
 * can be given. MikoPBX's access control grants a role the actions of a
 * module's controller one by one (MIKO's Access control management module
 * lists them by name); the README tells admins which to tick for each right.
 * Administrators have every action.
 *
 * - View: see schedules, calendars, rosters and the activity log.
 * - Edit duty: plan who is on duty (shift types, rosters, rotations,
 *   overrides). These change who answers, never where calls can go: every
 *   person is an employee and every "send elsewhere" target is one of the
 *   schedule's own destinations.
 * - Edit settings: create and delete schedules, change their chain and
 *   backups, link incoming routes, place test calls.
 */
final class Rights
{
    public const array VIEW = ['index', 'overview', 'schedule', 'calendar', 'options', 'audit'];

    public const array EDIT_DUTY = [
        'saveShiftType', 'deleteShiftType', 'orderShiftTypes', 'saveRoster', 'deleteRoster', 'setCell',
        'saveRotation', 'deleteRotation', 'orderRules', 'addOverride', 'addSwap', 'updateOverride', 'deleteOverride',
        'removeMember',
    ];

    public const array EDIT_SETTINGS = ['createSchedule', 'updateSchedule', 'deleteSchedule', 'setRuleBackup', 'linkRoute', 'unlinkRoute', 'testCall'];

    /**
     * Which change actions a role may use, for the page to show only the
     * controls that work.
     *
     * @param callable(string): bool $isAllowed whether the role may run an action
     * @return array<string, bool>
     */
    public static function writable(callable $isAllowed): array
    {
        $can = [];
        foreach ([...self::EDIT_DUTY, ...self::EDIT_SETTINGS] as $action) {
            $can[$action] = $isAllowed($action);
        }

        return $can;
    }
}
