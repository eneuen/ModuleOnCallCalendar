<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Lib;

use MikoPBX\Common\Models\SoundFiles;
use MikoPBX\Core\System\BeanstalkClient;
use MikoPBX\Core\System\SystemMessages;
use MikoPBX\Core\Workers\Cron\WorkerSafeScriptsCore;
use MikoPBX\Core\Workers\Libs\WorkerModelsEvents\Actions\ReloadDialplanAction;
use MikoPBX\Core\Workers\WorkerModelsEvents;
use MikoPBX\Modules\Config\ConfigClass;
use Throwable;

/**
 * Module hooks. The dialplan text comes from DialplanBuilder.
 *
 * This class lives on in long-running core workers, so every hook reads the
 * schedules afresh. If they can't be read, the hooks add nothing and the
 * error is logged.
 */
class OnCallCalendarConf extends ConfigClass
{
    public const string MODULE_ID = 'ModuleOnCallCalendar';

    /** Requests from the module page to the worker (the web process has no AMI connection). */
    public const string COMMANDS_TUBE = 'ModuleOnCallCalendar-commands';
    public const string COMMAND_PUBLISH = 'publish';
    public const string COMMAND_TEST_CALL = 'test_call';

    /** Each schedule's number, in [internal]: reached by routes, rules, IVRs and colleagues. */
    public function extensionGenInternal(): string
    {
        return DialplanBuilder::internal($this->schedules());
    }

    /** Each schedule's number, in [internal-transfer]: reached by transfers from phones. */
    public function extensionGenInternalTransfer(): string
    {
        return DialplanBuilder::internalTransfer($this->schedules());
    }

    /** The worker that keeps who is on duty published in AstDB. */
    public function getModuleWorkers(): array
    {
        return [
            [
                'type'   => WorkerSafeScriptsCore::CHECK_BY_BEANSTALK,
                'worker' => WorkerOnCallCalendar::class,
            ],
        ];
    }

    /**
     * Asks the worker to publish who is on duty now, after a change. If the
     * worker can't be reached the change still takes effect: the published
     * answers expire within DutyPublisher::MAX_AGE and calls then use the
     * AGI fallback.
     */
    public static function requestPublish(): void
    {
        try {
            (new BeanstalkClient(self::COMMANDS_TUBE))->publish(json_encode(['command' => self::COMMAND_PUBLISH]));
        } catch (Throwable $e) {
            SystemMessages::sysLogMsg(static::class, 'Could not ask the worker to publish: ' . $e->getMessage(), LOG_WARNING);
        }
    }

    /**
     * Asks the worker to place a test call: ring $extension, and when it
     * answers, dial the schedule's number from it.
     */
    public static function requestTestCall(int $scheduleId, string $extension): void
    {
        (new BeanstalkClient(self::COMMANDS_TUBE))->publish(json_encode([
            'command' => self::COMMAND_TEST_CALL, 'schedule' => $scheduleId, 'extension' => $extension,
        ]));
    }

    /** Enabling a module doesn't reload the dialplan by itself. */
    public function onAfterModuleEnable(): void
    {
        WorkerModelsEvents::invokeAction(ReloadDialplanAction::class);
    }

    /** Removes the module's dialplan once its hooks are no longer called. */
    public function onAfterModuleDisable(): void
    {
        WorkerModelsEvents::invokeAction(ReloadDialplanAction::class);
    }

    /** A sound file as a Playback path (without extension), or '' when it is gone. */
    private static function soundPath(int $soundFileId): string
    {
        $file = SoundFiles::findFirstById($soundFileId);

        return $file === null || (string)$file->path === '' ? '' : SoundFiles::resolveAsteriskAudioPath((string)$file->path);
    }

    /** @return list<array<string, mixed>> */
    private function schedules(): array
    {
        try {
            $schedules = (new ScheduleRepository(Databases::openReadOnly(ModuleContext::moduleDbFile())))->schedules();
            $directory = Employees::directory(Databases::openReadOnly(ModuleContext::coreDbFile()));
            foreach ($schedules as &$schedule) {
                $schedule['backup_extension'] = $directory[(int)$schedule['backup_user_id']]['extension'] ?? '';
                $schedule['greeting_path'] = $schedule['greeting_sound_id'] === null ? '' : self::soundPath((int)$schedule['greeting_sound_id']);
            }

            return $schedules;
        } catch (Throwable $e) {
            SystemMessages::sysLogMsg(static::class, 'Schedules not read: ' . $e->getMessage(), LOG_ERR);

            return [];
        }
    }
}
