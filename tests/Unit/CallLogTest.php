<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Modules\ModuleOnCallCalendar\Lib\CallLog;
use Modules\ModuleOnCallCalendar\Tests\Unit\Support\TestDatabases;
use PDO;
use PHPUnit\Framework\TestCase;

final class CallLogTest extends TestCase
{
    private DateTimeZone $tz;

    protected function setUp(): void
    {
        $this->tz = new DateTimeZone('Europe/Moscow');
    }

    private function ts(string $local): int
    {
        return (new DateTimeImmutable($local, $this->tz))->getTimestamp();
    }

    /** A cdr_general row as the PBX writes it. */
    private static function leg(string $dst, string $disposition, string $start, string $answer = '', string $isApp = ''): array
    {
        return ['dst_num' => $dst, 'disposition' => $disposition, 'start' => "$start.100", 'answer' => $answer === '' ? '' : "$answer.200",
            'is_app' => $isApp];
    }

    public function testAnsweredByThePersonOnDuty(): void
    {
        $legs = [
            self::leg('2290', 'NOANSWER', '2026-11-09 18:00:00'),
            self::leg('301', 'NOANSWER', '2026-11-09 18:00:01'),
            self::leg('302', 'ANSWERED', '2026-11-09 18:00:01', '2026-11-09 18:00:07'),
        ];

        self::assertSame(['outcome' => 'on_duty', 'answered_by' => '302', 'wait_seconds' => 7],
            CallLog::classify($legs, $this->ts('2026-11-09 18:00:00'), ['301', '302'], $this->tz));
    }

    public function testAnsweredByTheBackupThenVoicemailAndUnanswered(): void
    {
        $start = $this->ts('2026-11-09 18:00:00');
        $backup = [self::leg('301', 'NOANSWER', '2026-11-09 18:00:01'), self::leg('304', 'ANSWERED', '2026-11-09 18:00:10', '2026-11-09 18:00:12')];
        $voicemail = [self::leg('301', 'NOANSWER', '2026-11-09 18:00:01'), self::leg('voicemail', 'ANSWERED', '2026-11-09 18:00:20', '2026-11-09 18:00:20')];
        $queueApp = [self::leg('3100', 'ANSWERED', '2026-11-09 18:00:10', '2026-11-09 18:00:10', '1')];

        self::assertSame('other', CallLog::classify($backup, $start, ['301'], $this->tz)['outcome']);
        self::assertSame('voicemail', CallLog::classify($voicemail, $start, ['301'], $this->tz)['outcome']);
        // A queue's own record (is_app) is not someone answering.
        self::assertSame('unanswered', CallLog::classify($queueApp, $start, ['301'], $this->tz)['outcome']);
        self::assertSame('unanswered', CallLog::classify([], $start, ['301'], $this->tz)['outcome']);
    }

    public function testLegsAnsweredBeforeTheScheduleWasReachedDoNotCount(): void
    {
        // A test call: the admin's own phone answered first, then dialled the schedule.
        $legs = [self::leg('305', 'ANSWERED', '2026-11-09 17:59:50', '2026-11-09 17:59:55'),
            self::leg('303', 'ANSWERED', '2026-11-09 18:00:01', '2026-11-09 18:00:04')];

        self::assertSame('303', CallLog::classify($legs, $this->ts('2026-11-09 18:00:00'), ['303'], $this->tz)['answered_by']);
    }

    public function testHarvestWaitsForTheCallToEnd(): void
    {
        $module = TestDatabases::module();
        $start = $this->ts('2026-11-09 18:00:00');
        $notes = ["7/mikopbx-1.1" => "$start|1|Local/301@internal-users/n&Local/302@internal-users/n", '7/' => 'junk'];
        $legs = fn (string $linkedId): array => [self::leg('302', 'ANSWERED', '2026-11-09 18:00:01', '2026-11-09 18:00:05')];

        // Still in progress, or just ended: nothing stored yet; the malformed note is dropped.
        self::assertSame(['7/'], CallLog::harvest($module, $notes, ['mikopbx-1.1' => true], $legs, $this->tz, $start + 60));
        self::assertSame(['7/'], CallLog::harvest($module, $notes, [], $legs, $this->tz, $start + 5));

        self::assertSame(['7/mikopbx-1.1', '7/'], CallLog::harvest($module, $notes, [], $legs, $this->tz, $start + 60));
        $row = $module->query('SELECT * FROM m_Calls')->fetch(PDO::FETCH_ASSOC);
        self::assertSame([7, 'mikopbx-1.1', '1', '301,302', 'on_duty', '302', 5], [(int)$row['schedule_id'], $row['linkedid'],
            $row['start'], $row['on_duty'], $row['outcome'], $row['answered_by'], (int)$row['wait_seconds']]);
        // A second harvest of the same call doesn't store it twice.
        CallLog::harvest($module, $notes, [], $legs, $this->tz, $start + 70);
        self::assertSame(1, (int)$module->query('SELECT COUNT(*) FROM m_Calls')->fetchColumn());
    }

    public function testACallWithoutRecordsIsStoredAsUnansweredAfterAWhile(): void
    {
        $module = TestDatabases::module();
        $start = $this->ts('2026-11-09 18:00:00');
        $notes = ["7/x" => "$start|2|"];
        $none = static fn (string $id): array => [];

        self::assertSame([], CallLog::harvest($module, $notes, [], $none, $this->tz, $start + 60));
        self::assertSame(['7/x'], CallLog::harvest($module, $notes, [], $none, $this->tz, $start + 700));
        self::assertSame('unanswered', $module->query('SELECT outcome FROM m_Calls')->fetchColumn());
    }

    public function testPendingReadsAsteriskDatabase(): void
    {
        $astdb = new PDO('sqlite::memory:');
        $astdb->exec('CREATE TABLE astdb(key TEXT, value TEXT, PRIMARY KEY(key))');
        $astdb->exec("INSERT INTO astdb VALUES ('/OnCallCalendarCalls/7/mikopbx-1.1', '1|1|'), ('/OnCallCalendar/7/start', '1')");

        self::assertSame(['7/mikopbx-1.1' => '1|1|'], CallLog::pending($astdb));
    }
}
