#!/usr/bin/php
<?php

// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Live lookup of who is on duty, for one schedule. The dialplan runs it only
 * when the answer in AstDB is missing or expired (the module's worker keeps
 * it fresh), so normally no PHP runs during a call.
 *
 * Argument: the schedule id. Sets OCC_START, OCC_DIAL and OCC_GOTO on the
 * channel. On any error it sets nothing, and the dialplan treats the call as
 * a gap: the backup, then the final destination.
 */

declare(strict_types=1);

use MikoPBX\Core\Asterisk\AGI;
use MikoPBX\Core\System\SystemMessages;
use Modules\ModuleOnCallCalendar\Lib\Databases;
use Modules\ModuleOnCallCalendar\Lib\ModuleContext;

require_once 'Globals.php';

set_time_limit(5);

try {
    $agi = new AGI();
    $id = (int)($argv[1] ?? 0);
    $values = ModuleContext::currentValues(
        Databases::openReadOnly(ModuleContext::moduleDbFile()),
        Databases::openReadOnly(ModuleContext::coreDbFile()),
        time()
    )[$id] ?? null;
    if ($values !== null) {
        $agi->set_variable('OCC_START', $values['start']);
        $agi->set_variable('OCC_DIAL', $values['dial']);
        $agi->set_variable('OCC_GOTO', $values['goto']);
    }
} catch (Throwable $e) {
    SystemMessages::sysLogMsg('OnCallCalendar', 'Live duty lookup failed: ' . $e->getMessage(), LOG_ERR);
}
