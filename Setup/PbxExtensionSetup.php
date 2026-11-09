<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Setup;

use MikoPBX\Common\Models\PbxSettings;
use MikoPBX\Core\System\Directories;
use MikoPBX\Core\System\SystemMessages;
use MikoPBX\Core\System\Util;
use MikoPBX\Modules\Setup\PbxExtensionSetupBase;
use Modules\ModuleOnCallCalendar\Lib\Databases;
use Modules\ModuleOnCallCalendar\Lib\DialplanBuilder;
use Modules\ModuleOnCallCalendar\Lib\ScheduleNumbers;
use Modules\ModuleOnCallCalendar\Lib\ScheduleRepository;
use Throwable;

/**
 * Installer. The base class creates the tables from the model annotations,
 * links agi-bin/ into Asterisk's AGI folder and adds the sidebar entry.
 */
class PbxExtensionSetup extends PbxExtensionSetupBase
{
    /** The sidebar entry, in the modules group with a calendar icon. */
    public function addToSidebar(): bool
    {
        $key = "AdditionalMenuItem$this->moduleUniqueID";
        $menuSettings = PbxSettings::findFirstByKey($key) ?? new PbxSettings();
        $menuSettings->key = $key;
        $menuSettings->value = json_encode([
            'uniqid'        => $this->moduleUniqueID,
            'group'         => 'modules',
            'iconClass'     => 'calendar alternate outline',
            'caption'       => "Breadcrumb$this->moduleUniqueID",
            'showAtSidebar' => true,
        ]);

        return $menuSettings->save();
    }

    /**
     * Also removes what lives outside the module folder: the AGI link (the
     * core creates it on install but never removes it) and the module's AstDB
     * family.
     */
    public function unInstallFiles(bool $keepSettings = false): bool
    {
        foreach ([DialplanBuilder::LOOKUP_AGI, DialplanBuilder::TAKEOVER_AGI] as $script) {
            $link = Directories::getDir(Directories::AST_AGI_BIN_DIR) . '/' . $script;
            if (is_link($link) && str_starts_with((string)readlink($link), $this->moduleDir . '/')) {
                unlink($link);
            }
        }
        try {
            Util::getAstManager('off')->sendRequestTimeout('DBDelTree', ['Family' => DialplanBuilder::FAMILY]);
        } catch (Throwable $e) {
            SystemMessages::sysLogMsg(static::class, 'Could not clear the AstDB family: ' . $e->getMessage(), LOG_WARNING);
        }

        return parent::unInstallFiles($keepSettings);
    }

    /**
     * Also removes the sidebar entry's setting, which the core leaves in its
     * settings table; installing adds it again.
     */
    public function unInstallDB(bool $keepSettings = false): bool
    {
        PbxSettings::findFirstByKey("AdditionalMenuItem$this->moduleUniqueID")?->delete();
        if (!$keepSettings) {
            $this->removeScheduleNumbers();
        }

        return parent::unInstallDB($keepSettings);
    }

    /**
     * Without kept settings the schedules are gone for good, so their numbers
     * are released in the core too. The core refuses for a number that a
     * route or rule still points at; that is logged, and the admin sees the
     * number in use there.
     */
    private function removeScheduleNumbers(): void
    {
        try {
            $schedules = (new ScheduleRepository(Databases::openReadOnly($this->moduleDir . '/db/module.db')))->schedules();
        } catch (Throwable $e) {
            SystemMessages::sysLogMsg(static::class, 'Schedule numbers not released: ' . $e->getMessage(), LOG_WARNING);

            return;
        }
        foreach ($schedules as $schedule) {
            $reasons = ScheduleNumbers::unregister((string)$schedule['number']);
            if ($reasons !== []) {
                SystemMessages::sysLogMsg(static::class, "Number {$schedule['number']} kept: " . implode(' ', $reasons), LOG_WARNING);
            }
        }
    }
}
