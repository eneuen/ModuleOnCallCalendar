<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Lib;

use MikoPBX\Core\System\SystemMessages;
use MikoPBX\Core\Workers\Libs\WorkerModelsEvents\Actions\ReloadDialplanAction;
use MikoPBX\Core\Workers\WorkerModelsEvents;
use MikoPBX\Modules\Config\ConfigClass;
use Modules\ModuleOnCallCalendar\Models\Schedule;
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

    /** @return list<array<string, mixed>> */
    private function schedules(): array
    {
        try {
            return array_values(Schedule::find(['order' => 'id'])->toArray());
        } catch (Throwable $e) {
            SystemMessages::sysLogMsg(static::class, 'Schedules not read: ' . $e->getMessage(), LOG_ERR);

            return [];
        }
    }
}
