<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Tests\Unit\Support;

use PDO;

/**
 * In-memory databases for the tests: the module's own, built from the model
 * annotations (as the core builds it on install), and a minimal copy of the
 * core's configuration database.
 */
final class TestDatabases
{
    private const string MODELS = __DIR__ . '/../../../Models';

    public static function module(): PDO
    {
        $pdo = self::memory();
        foreach (glob(self::MODELS . '/*.php') ?: [] as $model) {
            foreach (self::tableSql((string)file_get_contents($model)) as $sql) {
                $pdo->exec($sql);
            }
        }

        return $pdo;
    }

    /**
     * The core tables the module reads, with a few employees.
     *
     * @param array<int, array{0: string, 1: ?string}> $employees user id => [name, SIP extension or null]
     */
    public static function core(array $employees = [], string $timezone = 'Europe/Berlin'): PDO
    {
        $pdo = self::memory();
        $pdo->exec('CREATE TABLE m_Users (id INTEGER PRIMARY KEY, email VARCHAR, username VARCHAR, language VARCHAR, avatar VARCHAR)');
        $pdo->exec('CREATE TABLE m_Extensions (id INTEGER PRIMARY KEY AUTOINCREMENT, number VARCHAR, type VARCHAR, callerid VARCHAR,
            userid INTEGER, show_in_phonebook VARCHAR, public_access VARCHAR, is_general_user_number VARCHAR)');
        $pdo->exec('CREATE TABLE m_PbxSettings (key VARCHAR PRIMARY KEY, value VARCHAR)');
        $pdo->exec('CREATE TABLE m_IncomingRoutingTable (id INTEGER PRIMARY KEY AUTOINCREMENT, rulename VARCHAR, number VARCHAR,
            extension VARCHAR, provider VARCHAR, priority INTEGER, timeout INTEGER, action VARCHAR, note VARCHAR, audio_message_id VARCHAR)');
        $pdo->exec('CREATE TABLE m_CallQueues (id INTEGER PRIMARY KEY AUTOINCREMENT, extension VARCHAR, name VARCHAR,
            caller_hear VARCHAR, timeout_to_redirect_to_extension INTEGER, timeout_extension VARCHAR, redirect_to_extension_if_empty VARCHAR)');
        $pdo->exec('CREATE TABLE m_OutWorkTimes (id INTEGER PRIMARY KEY AUTOINCREMENT, extension VARCHAR, description VARCHAR)');
        $pdo->exec('CREATE TABLE m_IvrMenu (id INTEGER PRIMARY KEY AUTOINCREMENT, uniqid VARCHAR, extension VARCHAR, name VARCHAR, timeout_extension VARCHAR)');
        $pdo->exec('CREATE TABLE m_IvrMenuActions (id INTEGER PRIMARY KEY AUTOINCREMENT, ivr_menu_id VARCHAR, digits VARCHAR, extension VARCHAR)');
        $pdo->exec('CREATE TABLE m_ExtensionForwardingRights (id INTEGER PRIMARY KEY AUTOINCREMENT, extension VARCHAR, forwarding VARCHAR,
            forwardingonbusy VARCHAR, forwardingonunavailable VARCHAR, ringlength INTEGER)');
        $pdo->exec("INSERT INTO m_IncomingRoutingTable (id, rulename, number, extension, priority, timeout)
            VALUES (1, 'default action', '', 'busy', 9999, 0), (2, 'Service DID', '5551000', '2000', 1, 60), (3, 'Sales DID', '5551001', '201', 2, 20)");
        $pdo->exec('CREATE TABLE m_SoundFiles (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR, path VARCHAR, category VARCHAR)');
        $pdo->exec("INSERT INTO m_SoundFiles (id, name, path, category) VALUES (5, 'Greeting', '/storage/greeting.mp3', 'custom')");
        $pdo->prepare('INSERT INTO m_PbxSettings VALUES (?, ?)')->execute(['PBXTimezone', $timezone]);
        // What a fresh PBX has, plus a queue, an IVR menu and a parking slot.
        foreach ([['voicemail', 'SYSTEM'], ['hangup', 'SYSTEM'], ['busy', 'SYSTEM'], ['2000', 'QUEUE'], ['2003', 'IVR MENU'],
            ['800', 'PARKING']] as [$number, $type]) {
            $pdo->prepare('INSERT INTO m_Extensions (number, type, callerid) VALUES (?, ?, ?)')->execute([$number, $type, $number]);
        }
        $pdo->exec("INSERT INTO m_CallQueues (extension, name, caller_hear, timeout_to_redirect_to_extension, timeout_extension,
            redirect_to_extension_if_empty) VALUES ('2000', 'Service queue', 'moh', 60, 'voicemail', '')");
        foreach ($employees as $id => [$name, $extension]) {
            $pdo->prepare('INSERT INTO m_Users (id, username) VALUES (?, ?)')->execute([$id, $name]);
            if ($extension !== null) {
                $pdo->prepare("INSERT INTO m_Extensions (number, type, callerid, userid) VALUES (?, 'SIP', ?, ?)")
                    ->execute([$extension, $name, $id]);
                $pdo->prepare("INSERT INTO m_Extensions (number, type, callerid, userid) VALUES (?, 'EXTERNAL', ?, ?)")
                    ->execute(['8900' . $extension, $name, $id]);
            }
        }

        return $pdo;
    }

    private static function memory(): PDO
    {
        return new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /**
     * CREATE TABLE and CREATE INDEX statements for one model file, read from
     * its setSource(), @Column, @Identity and @Indexes annotations.
     *
     * @return list<string>
     */
    private static function tableSql(string $source): array
    {
        preg_match("/setSource\\('([^']+)'\\)/", $source, $m);
        $table = $m[1];

        preg_match_all('/\/\*\*((?:(?!\*\/).)*?)\*\/\s*public \$(\w+)(?:\s*=[^;]*)?;/s', $source, $props, PREG_SET_ORDER);
        $columns = [];
        foreach ($props as [, $doc, $name]) {
            if (!preg_match('/@Column\(type="(\w+)"/', $doc, $type)) {
                continue;
            }
            if (str_contains($doc, '@Identity')) {
                $columns[] = "`$name` INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL";
                continue;
            }
            $sqlType = $type[1] === 'integer' ? 'INTEGER' : 'VARCHAR';
            $null = str_contains($doc, 'nullable=false') ? 'NOT NULL' : 'NULL';
            $default = preg_match('/default="([^"]*)"/', $doc, $d) ? " DEFAULT '{$d[1]}'" : '';
            $columns[] = "`$name` $sqlType $null$default";
        }
        $sql = ["CREATE TABLE `$table` (\n  " . implode(",\n  ", $columns) . "\n)"];

        preg_match_all("/\\[name='(\\w+)', columns=\\[([^\\]]+)\\], type='(\\w*)'\\]/", $source, $indexes, PREG_SET_ORDER);
        foreach ($indexes as [, $name, $cols, $type]) {
            $cols = implode(',', array_map(fn (string $c): string => '`' . trim($c, " '") . '`', explode(',', $cols)));
            $unique = strtoupper($type) === 'UNIQUE' ? 'UNIQUE ' : '';
            $sql[] = "CREATE {$unique}INDEX `i_{$table}_$name` ON `$table` ($cols)";
        }

        return $sql;
    }
}
