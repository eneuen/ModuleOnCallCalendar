<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Setup;

use MikoPBX\Common\Models\PbxSettings;
use MikoPBX\Core\System\Directories;
use MikoPBX\Core\System\SystemMessages;
use MikoPBX\Core\System\Util;
use MikoPBX\Modules\Setup\PbxExtensionSetupBase;
use Modules\ModuleOnCallCalendar\Lib\DialplanBuilder;
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
        $link = Directories::getDir(Directories::AST_AGI_BIN_DIR) . '/' . DialplanBuilder::LOOKUP_AGI;
        if (is_link($link) && str_starts_with((string)readlink($link), $this->moduleDir . '/')) {
            unlink($link);
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

        return parent::unInstallDB($keepSettings);
    }
}
