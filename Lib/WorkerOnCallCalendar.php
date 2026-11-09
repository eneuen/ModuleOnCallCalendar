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

    /** A request from the module page: publish now. */
    public function onCommand(BeanstalkClient $message): void
    {
        $this->publish();
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
