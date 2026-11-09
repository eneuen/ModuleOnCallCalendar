<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Lib;

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
 * - A message on OnCallCalendarConf::COMMANDS_TUBE (sent after every save on
 *   the module page; the web process has no AMI connection) publishes at once.
 *
 * Nothing in the loop may throw: a worker that keeps crashing makes the core
 * disable the whole module, which would cut off the lines routed to it.
 */
class WorkerOnCallCalendar extends WorkerBase
{
    private const int PUBLISH_EVERY = 60;

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
            foreach ($values as $id => $keys) {
                foreach ($keys as $key => $value) {
                    if (($this->published[$id][$key] ?? null) !== $value || $key === 'until') {
                        $ami->DBPut(DialplanBuilder::FAMILY, "$id/$key", $value);
                    }
                }
                // Republish just after this answer stops being valid.
                $this->nextPublish = min($this->nextPublish, (int)$keys['until'] + 1);
            }
            foreach (array_diff_key($this->published, $values) as $id => $unused) {
                $ami->sendRequestTimeout('DBDelTree', ['Family' => DialplanBuilder::FAMILY, 'Key' => (string)$id]);
            }
            $this->published = $values;
        } catch (Throwable $e) {
            SystemMessages::sysLogMsg(static::class, 'Publishing who is on duty failed: ' . $e->getMessage(), LOG_ERR);
            $this->published = [];
            $this->nextPublish = $now + 10;
        }
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
