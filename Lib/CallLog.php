<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Lib;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * The calls that reached each schedule, for the activity report.
 *
 * When a call reaches a schedule's number, the dialplan notes it in AstDB
 * (family FAMILY, key <schedule id>/<linkedid>, value "<epoch>|<start>|<dial
 * string>"), so nothing runs during the call. Once the call is over (no
 * channel of it left in Asterisk), the worker reads the PBX's call records
 * for it, works out how it ended, stores a row in m_Calls and deletes the
 * note. Pure apart from the databases passed in, so it is unit-tested.
 */
final class CallLog
{
    public const string FAMILY = 'OnCallCalendarCalls';
    public const string TABLE = 'm_Calls';

    public const string ON_DUTY = 'on_duty';
    public const string OTHER = 'other';
    public const string VOICEMAIL = 'voicemail';
    public const string UNANSWERED = 'unanswered';

    /** A call's records are read once it has been over for this long. */
    private const int SETTLE_SECONDS = 15;

    /** A note whose call left no records at all is stored as unanswered after this long. */
    private const int GIVE_UP_SECONDS = 600;

    /**
     * The notes still waiting in AstDB, from Asterisk's own database file.
     *
     * @return array<string, string> AstDB key without the family (<schedule id>/<linkedid>) => value
     */
    public static function pending(PDO $astdb): array
    {
        $stmt = $astdb->prepare('SELECT key, value FROM astdb WHERE key LIKE ?');
        $stmt->execute(['/' . self::FAMILY . '/%']);
        $notes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $notes[substr((string)$row['key'], strlen(self::FAMILY) + 2)] = (string)$row['value'];
        }

        return $notes;
    }

    /**
     * Stores the calls that are over and returns the notes to delete.
     *
     * @param array<string, string> $notes pending()
     * @param array<string, true> $active linkedids that still have a channel
     * @param callable(string): list<array<string, mixed>> $legsOf the call records (cdr_general rows) of a linkedid
     * @return list<string> AstDB keys (without the family) that are done
     */
    public static function harvest(PDO $module, array $notes, array $active, callable $legsOf, DateTimeZone $tz, int $now): array
    {
        $done = [];
        foreach ($notes as $key => $value) {
            [$scheduleId, $linkedId] = array_pad(explode('/', $key, 2), 2, '');
            $parts = explode('|', $value);
            $startedAt = (int)$parts[0];
            if ((int)$scheduleId <= 0 || $linkedId === '' || $startedAt <= 0) {
                $done[] = $key; // malformed: drop it
                continue;
            }
            if (isset($active[$linkedId]) || $now - $startedAt < self::SETTLE_SECONDS) {
                continue;
            }
            $legs = $legsOf($linkedId);
            if ($legs === [] && $now - $startedAt < self::GIVE_UP_SECONDS) {
                continue; // the records may not be written yet
            }
            $onDuty = self::extensions((string)($parts[2] ?? ''));
            $result = self::classify($legs, $startedAt, $onDuty, $tz);
            $module->prepare('INSERT OR IGNORE INTO ' . self::TABLE . ' (schedule_id, linkedid, started_at, start, on_duty, outcome,
                answered_by, wait_seconds) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                    (int)$scheduleId, $linkedId, $startedAt, (string)($parts[1] ?? ''), implode(',', $onDuty),
                    $result['outcome'], $result['answered_by'], $result['wait_seconds'],
                ]);
            $done[] = $key;
        }

        return $done;
    }

    /**
     * How a call ended, from its call records: the first leg answered after
     * the call reached the schedule decides. Records from before (the
     * caller's own leg into the schedule, the first leg of a test call) are
     * ignored.
     *
     * @param list<array<string, mixed>> $legs cdr_general rows
     * @param list<string> $onDuty extensions rung as the people on duty
     * @param DateTimeZone $tz the PBX's time zone, in which call records store their times
     * @return array{outcome: string, answered_by: ?string, wait_seconds: ?int}
     */
    public static function classify(array $legs, int $startedAt, array $onDuty, DateTimeZone $tz): array
    {
        $first = null;
        foreach ($legs as $leg) {
            $answer = self::timestamp((string)($leg['answer'] ?? ''), $tz);
            if ((string)($leg['disposition'] ?? '') !== 'ANSWERED' || $answer === null || $answer < $startedAt
                || (string)($leg['is_app'] ?? '') === '1') {
                continue;
            }
            if ($first === null || $answer < $first['answer']) {
                $first = ['answer' => $answer, 'dst' => (string)($leg['dst_num'] ?? '')];
            }
        }
        if ($first === null) {
            return ['outcome' => self::UNANSWERED, 'answered_by' => null, 'wait_seconds' => null];
        }
        $outcome = match (true) {
            in_array($first['dst'], $onDuty, true) => self::ON_DUTY,
            $first['dst'] === 'voicemail' => self::VOICEMAIL,
            default => self::OTHER,
        };

        return ['outcome' => $outcome, 'answered_by' => $first['dst'], 'wait_seconds' => max(0, $first['answer'] - $startedAt)];
    }

    /** Calls older than this many days are deleted. */
    public static function prune(PDO $module, int $now, int $days = 400): void
    {
        $module->prepare('DELETE FROM ' . self::TABLE . ' WHERE started_at < ?')->execute([$now - $days * 86400]);
    }

    /**
     * The extensions in a dial string (Local/<ext>@internal-users/n legs joined by &).
     *
     * @return list<string>
     */
    public static function extensions(string $dial): array
    {
        preg_match_all('#Local/(\d+)@#', $dial, $m);

        return array_values(array_unique($m[1]));
    }

    /** A cdr_general time ("Y-m-d H:i:s.v", the PBX's local time) as a timestamp. */
    private static function timestamp(string $value, DateTimeZone $tz): ?int
    {
        $time = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr($value, 0, 19), $tz);

        return $time === false ? null : $time->getTimestamp();
    }
}
