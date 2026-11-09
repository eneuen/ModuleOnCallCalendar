<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Lib;

/**
 * A roster's or rotation's own backup, as one string the resolver carries
 * and the worker publishes in AstDB (key "backup"), so the dialplan can use
 * it without a reload:
 *
 *   ''                                   the schedule's own backup
 *   none                                 no backup: straight to the final destination
 *   dial|<seconds>|Local/<ext>@internal-users/n   ring this person for that long
 *   queue|<number>                       hand the call to this queue
 */
final class BackupSpec
{
    public const string NONE = 'none';

    /**
     * The spec for a rule's backup fields. A backup person whose extension
     * is gone means no backup, as for the schedule's own backup.
     *
     * @param array<string, mixed> $rule backup_type, backup_number, backup_user_id, backup_ring_seconds
     * @param array<int, array{name: string, extension: string}> $directory Employees::directory()
     */
    public static function of(array $rule, array $directory): string
    {
        return match ((string)($rule['backup_type'] ?? '')) {
            'none'   => self::NONE,
            'queue'  => self::isNumber((string)($rule['backup_number'] ?? '')) ? 'queue|' . $rule['backup_number'] : self::NONE,
            'member' => self::member((int)($rule['backup_user_id'] ?? 0), (int)($rule['backup_ring_seconds'] ?? 20), $directory),
            default  => '',
        };
    }

    /** Whether a published spec is well formed: only these reach the dialplan. */
    public static function isValid(string $spec): bool
    {
        return $spec === '' || $spec === self::NONE
            || preg_match('#^(dial\|\d{1,3}\|Local/\d{1,20}@internal-users/n|queue\|\d{1,20})$#', $spec) === 1;
    }

    /**
     * @param array<int, array{name: string, extension: string}> $directory
     */
    private static function member(int $userId, int $seconds, array $directory): string
    {
        $extension = (string)($directory[$userId]['extension'] ?? '');
        if (!self::isNumber($extension)) {
            return self::NONE;
        }

        return 'dial|' . max(5, min(300, $seconds)) . "|Local/$extension@internal-users/n";
    }

    private static function isNumber(string $value): bool
    {
        return preg_match('/^\d{1,20}$/', $value) === 1;
    }
}
