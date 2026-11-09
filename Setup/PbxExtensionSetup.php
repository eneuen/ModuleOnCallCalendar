<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Setup;

use MikoPBX\Common\Models\PbxSettings;
use MikoPBX\Modules\Setup\PbxExtensionSetupBase;

/**
 * Installer. The base class creates the tables from the model annotations
 * and adds the sidebar entry.
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
     * Also removes the sidebar entry's setting, which the core leaves in its
     * settings table; installing adds it again.
     */
    public function unInstallDB(bool $keepSettings = false): bool
    {
        PbxSettings::findFirstByKey("AdditionalMenuItem$this->moduleUniqueID")?->delete();

        return parent::unInstallDB($keepSettings);
    }
}
