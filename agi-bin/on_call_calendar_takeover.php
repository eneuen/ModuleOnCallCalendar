#!/usr/bin/php
<?php

// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * *22 take-over from a phone. Arguments: the caller's PJSIP endpoint and
 * the schedule number dialled after *22 (may be empty). Sets OCC_TAKEOVER to
 * "ok" or a reason (Lib/TakeOver), which the dialplan turns into a
 * confirmation or an error tone, and asks the worker to publish at once.
 */

declare(strict_types=1);

use MikoPBX\Core\Asterisk\AGI;
use MikoPBX\Core\System\SystemMessages;
use Modules\ModuleOnCallCalendar\Lib\Databases;
use Modules\ModuleOnCallCalendar\Lib\ModuleContext;
use Modules\ModuleOnCallCalendar\Lib\OnCallCalendarConf;
use Modules\ModuleOnCallCalendar\Lib\TakeOver;

require_once 'Globals.php';

set_time_limit(10);

$agi = new AGI();
$result = 'error';
try {
    $outcome = TakeOver::take(
        Databases::openWritable(ModuleContext::moduleDbFile()),
        Databases::openReadOnly(ModuleContext::coreDbFile()),
        (string)($argv[1] ?? ''),
        (string)preg_replace('/\D/', '', (string)($argv[2] ?? '')),
        time()
    );
    $result = $outcome['result'];
    if ($result === TakeOver::OK) {
        OnCallCalendarConf::requestPublish();
    }
} catch (Throwable $e) {
    SystemMessages::sysLogMsg('OnCallCalendar', 'Take-over by phone failed: ' . $e->getMessage(), LOG_ERR);
}
$agi->set_variable('OCC_TAKEOVER', $result);
