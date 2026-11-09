<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Lib;

use MikoPBX\Core\Asterisk\AsteriskManager;
use MikoPBX\Core\System\BeanstalkClient;
use MikoPBX\Core\System\SystemMessages;
use MikoPBX\Core\System\Util;
use MikoPBX\Core\Workers\WorkerBase;
use Throwable;

require_once 'Globals.php';

/**
 * Keeps who is on duty published in AstDB. The supervisor starts it as its
 * own PHP process and checks it over Beanstalk (CHECK_BY_BEANSTALK).
 *
 * - At start: clears the module's AstDB family and publishes every schedule.
 * - Every PUBLISH_EVERY seconds, and as soon as an answer is about to change
 *   (the earliest "until"), it publishes again.
 * - Every HARVEST_EVERY seconds, calls that are over move from their AstDB
 *   notes into the call log for the activity report (CallLog).
 * - A message on OnCallCalendarConf::COMMANDS_TUBE (sent after every save on
 *   the module page; the web process has no AMI connection) publishes at once.
 *
 * Nothing in the loop may throw: a worker that keeps crashing makes the core
 * disable the whole module, which would cut off the lines routed to it.
 */
class WorkerOnCallCalendar extends WorkerBase
{
    private const int PUBLISH_EVERY = 60;

    /** How often finished calls are moved from AstDB notes into the call log. */
    private const int HARVEST_EVERY = 30;

    private int $nextHarvest = 0;

    private string $lastPrune = '';

    /** @var array<int, array<string, string>> what is in AstDB now, by schedule id */
    private array $published = [];

    private int $nextPublish = 0;

    public function start(array $argv): void
    {
        $client = new BeanstalkClient(self::class);
        $client->subscribe(OnCallCalendarConf::COMMANDS_TUBE, [$this, 'onCommand']);
        $client->subscribe($this->makePingTubeName(self::class), [$this, 'pingCallBack']);

        $this->clearAll();
        while ($this->needRestart === false) {
            $client->wait(1);
            if (time() >= $this->nextPublish) {
                $this->publish();
            }
            if (time() >= $this->nextHarvest) {
                $this->harvestCalls();
            }
        }
    }

    /**
     * A request from the module page: publish now, or place a test call. The
     * page has validated it; it is checked again here, because anything that
     * can publish to the queue reaches this.
     */
    public function onCommand(BeanstalkClient $message): void
    {
        try {
            $request = json_decode($message->getBody(), true, 4, JSON_THROW_ON_ERROR);
            match (is_array($request) ? ($request['command'] ?? '') : '') {
                OnCallCalendarConf::COMMAND_PUBLISH => $this->publish(),
                OnCallCalendarConf::COMMAND_TEST_CALL => $this->testCall((int)($request['schedule'] ?? 0), (string)($request['extension'] ?? '')),
                default => throw new \InvalidArgumentException('Unknown command'),
            };
        } catch (Throwable $e) {
            SystemMessages::sysLogMsg(static::class, 'Command not done: ' . $e->getMessage(), LOG_WARNING);
        }
    }

    /**
     * Rings the extension and, once it answers, dials the schedule's number
     * from it, as the core's own click-to-call does. The call then runs the
     * schedule's real chain.
     */
    private function testCall(int $scheduleId, string $extension): void
    {
        $schedule = (new ScheduleRepository(Databases::openReadOnly(ModuleContext::moduleDbFile())))->schedule($scheduleId);
        $extensions = array_column(Employees::directory(Databases::openReadOnly(ModuleContext::coreDbFile())), 'extension');
        if ($schedule === null || !in_array($extension, $extensions, true)) {
            throw new \InvalidArgumentException('Invalid test call request');
        }
        $result = Util::amiOriginate($extension, '', (string)$schedule['number']);
        if (strcasecmp((string)($result['Response'] ?? ''), 'Success') !== 0) {
            throw new \RuntimeException('Originate refused: ' . ($result['Message'] ?? 'no response'));
        }
    }

    private function publish(): void
    {
        $now = time();
        $this->nextPublish = $now + self::PUBLISH_EVERY;
        try {
            $values = ModuleContext::currentValues(
                Databases::openReadOnly(ModuleContext::moduleDbFile()),
                Databases::openReadOnly(ModuleContext::coreDbFile()),
                $now
            );
            $ami = Util::getAstManager('off');
            $written = [];
            foreach ($values as $id => $keys) {
                $before = $this->published[$id] ?? null;
                $changed = $before === null || array_diff_key($before, ['until' => 0]) !== array_diff_key($keys, ['until' => 0]);
                if ($this->write($ami, $id, $keys, $changed)) {
                    $written[$id] = $keys;
                }
                // Republish just after this answer stops being valid.
                $this->nextPublish = min($this->nextPublish, (int)$keys['until'] + 1);
            }
            foreach (array_diff_key($this->published, $values) as $id => $unused) {
                $ami->sendRequestTimeout('DBDelTree', ['Family' => DialplanBuilder::FAMILY, 'Key' => (string)$id]);
            }
            $this->published = $written;
            if (count($written) < count($values)) {
                // Some answers were not written: try again soon.
                $this->nextPublish = min($this->nextPublish, $now + 10);
            }
        } catch (Throwable $e) {
            SystemMessages::sysLogMsg(static::class, 'Publishing who is on duty failed: ' . $e->getMessage(), LOG_ERR);
            $this->published = [];
            $this->nextPublish = $now + 10;
        }
    }

    /**
     * Moves the calls that are over from their AstDB notes into the call log
     * (CallLog). A call counts as over only when Asterisk confirms it has no
     * channel left; if Asterisk can't be asked, nothing is harvested.
     */
    private function harvestCalls(): void
    {
        $now = time();
        $this->nextHarvest = $now + self::HARVEST_EVERY;
        try {
            $notes = CallLog::pending(Databases::openReadOnly(ModuleContext::astDbFile()));
            $module = Databases::openWritable(ModuleContext::moduleDbFile());
            if ($this->lastPrune !== date('Y-m-d')) {
                $this->lastPrune = date('Y-m-d');
                CallLog::prune($module, $now);
            }
            if ($notes === []) {
                return;
            }
            $ami = Util::getAstManager('off');
            $channels = $ami->sendRequestTimeout('CoreShowChannels');
            if (strcasecmp((string)($channels['Response'] ?? ''), 'Success') !== 0) {
                return;
            }
            $active = [];
            foreach ($channels['data']['CoreShowChannel'] ?? [] as $channel) {
                if (isset($channel['Linkedid'])) {
                    $active[(string)$channel['Linkedid']] = true;
                }
            }
            $cdr = Databases::openReadOnly(ModuleContext::cdrDbFile());
            $legsOf = static function (string $linkedId) use ($cdr): array {
                $stmt = $cdr->prepare('SELECT dst_num, disposition, start, answer, is_app FROM cdr_general WHERE linkedid = ? ORDER BY id');
                $stmt->execute([$linkedId]);

                return $stmt->fetchAll(\PDO::FETCH_ASSOC);
            };
            $core = Databases::openReadOnly(ModuleContext::coreDbFile());
            foreach (CallLog::harvest($module, $notes, $active, $legsOf, ModuleContext::timezone($core), $now) as $key) {
                $ami->sendRequestTimeout('DBDel', ['Family' => CallLog::FAMILY, 'Key' => $key]);
            }
        } catch (Throwable $e) {
            SystemMessages::sysLogMsg(static::class, 'Reading finished calls failed: ' . $e->getMessage(), LOG_WARNING);
        }
    }

    /**
     * Writes one schedule's answer: every key, every time, so an answer lost
     * or changed outside the worker is put right within a minute. When the
     * answer changed, "until" is set to 0 first and written last, so a call
     * arriving mid-write finds an expired answer (and asks the live lookup)
     * rather than a new "start" next to an old "dial". Any failed write
     * leaves the answer expired.
     *
     * @param array<string, string> $keys
     * @return bool whether everything was written
     */
    private function write(AsteriskManager $ami, int $id, array $keys, bool $changed): bool
    {
        $put = static fn (string $key, string $value): bool => strcasecmp(
            (string)($ami->DBPut(DialplanBuilder::FAMILY, "$id/$key", $value)['Response'] ?? ''),
            'Success'
        ) === 0;
        if ($changed && !$put('until', '0')) {
            return false;
        }
        foreach (['start', 'dial', 'goto', 'backup'] as $key) {
            if (!$put($key, (string)$keys[$key])) {
                $put('until', '0');

                return false;
            }
        }

        return $put('until', (string)$keys['until']);
    }

    private function clearAll(): void
    {
        try {
            Util::getAstManager('off')->sendRequestTimeout('DBDelTree', ['Family' => DialplanBuilder::FAMILY]);
        } catch (Throwable $e) {
            SystemMessages::sysLogMsg(static::class, 'Clearing old duty answers failed: ' . $e->getMessage(), LOG_WARNING);
        }
    }
}

// Start the worker when this file is run as a script by the supervisor.
WorkerOnCallCalendar::startWorker($argv ?? []);
