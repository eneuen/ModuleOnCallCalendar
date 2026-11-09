<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Lib;

use PDO;

/**
 * The PBX's employees and their internal extension, read from the core's
 * configuration database. Members of a schedule point at an employee
 * (m_Users id), never at a number, so a changed extension is followed and a
 * deleted employee simply has no extension any more.
 */
final class Employees
{
    /**
     * Every employee that has an internal (SIP) extension.
     *
     * @return array<int, array{name: string, extension: string}> keyed by user id
     */
    public static function directory(PDO $core): array
    {
        $rows = $core->query("SELECT u.id, u.username, e.number FROM m_Users u
            JOIN m_Extensions e ON e.userid = u.id AND e.type = 'SIP'
            ORDER BY u.username, e.number")->fetchAll(PDO::FETCH_ASSOC);
        $directory = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            if (!isset($directory[$id])) {
                $directory[$id] = ['name' => (string)$row['username'], 'extension' => (string)$row['number']];
            }
        }

        return $directory;
    }
}
