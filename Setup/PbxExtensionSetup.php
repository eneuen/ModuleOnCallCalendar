<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Setup;

use MikoPBX\Common\Models\PbxSettings;
use MikoPBX\Common\Providers\ModelsAnnotationsProvider;
use MikoPBX\Core\System\Directories;
use MikoPBX\Core\System\SystemMessages;
use MikoPBX\Core\System\Util;
use MikoPBX\Modules\Setup\PbxExtensionSetupBase;
use Modules\ModuleOnCallCalendar\Lib\Databases;
use Modules\ModuleOnCallCalendar\Lib\DialplanBuilder;
use Modules\ModuleOnCallCalendar\Lib\ScheduleNumbers;
use Modules\ModuleOnCallCalendar\Lib\ScheduleRepository;
use PDO;
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
     * After the core creates or extends the tables, adds any column a model
     * declares that its table still lacks. The core builds the tables from
     * model metadata that it caches in Redis for 10 minutes, so an update
     * installed within that time can leave a new column out, and every query
     * that uses it would fail.
     */
    public function installDB(): bool
    {
        if (!parent::installDB()) {
            return false;
        }
        try {
            $this->addMissingColumns();
        } catch (Throwable $e) {
            SystemMessages::sysLogMsg(static::class, 'Database update failed: ' . $e->getMessage(), LOG_ERR);
            $this->messages[] = 'On Call Calendar could not update its database: ' . $e->getMessage();

            return false;
        }

        return true;
    }

    private function addMissingColumns(): void
    {
        $db = Databases::openWritable($this->moduleDir . '/db/module.db');
        $annotations = $this->di->get(ModelsAnnotationsProvider::SERVICE_NAME);
        foreach (glob($this->moduleDir . '/Models/*.php') ?: [] as $file) {
            $class = "Modules\\$this->moduleUniqueID\\Models\\" . pathinfo($file, PATHINFO_FILENAME);
            $table = (new $class())->getSource();
            $existing = array_column($db->query("PRAGMA table_info(`$table`)")->fetchAll(PDO::FETCH_ASSOC), 'name');
            foreach ($annotations->get($class)->getPropertiesAnnotations() ?: [] as $property => $collection) {
                if (in_array($property, $existing, true) || !$collection->has('Column')) {
                    continue;
                }
                $column = $collection->get('Column');
                $type = $column->getArgument('type') === 'integer' ? 'INTEGER' : 'VARCHAR';
                $default = $column->hasArgument('default') ? ' DEFAULT ' . $db->quote((string)$column->getArgument('default')) : '';
                // SQLite can only add a NOT NULL column with a default.
                $notNull = $default !== '' && $column->getArgument('nullable') === false ? ' NOT NULL' : '';
                $db->exec("ALTER TABLE `$table` ADD COLUMN `$property` $type$notNull$default");
                SystemMessages::sysLogMsg(static::class, "Added the missing column $table.$property", LOG_NOTICE);
            }
        }
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
