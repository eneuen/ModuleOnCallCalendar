<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Tests\Unit;

use Modules\ModuleOnCallCalendar\Lib\EditorException;
use Modules\ModuleOnCallCalendar\Lib\RouteLinker;
use Modules\ModuleOnCallCalendar\Lib\ScheduleEditor;
use Modules\ModuleOnCallCalendar\Lib\ScheduleRepository;
use Modules\ModuleOnCallCalendar\Tests\Unit\Support\TestDatabases;
use PDO;
use PHPUnit\Framework\TestCase;

final class RouteLinkerTest extends TestCase
{
    private const int NOW = 1794387600;

    private PDO $module;

    private PDO $core;

    /** @var list<array{int, string, int}> */
    private array $saved = [];

    private ?string $saveError = null;

    protected function setUp(): void
    {
        $this->module = TestDatabases::module();
        $this->core = TestDatabases::core([110 => ['Anna', '201'], 111 => ['Ben', '202']]);
    }

    private function linker(): RouteLinker
    {
        // Stands in for the core's route save: records the call and applies it to the test core database.
        $save = function (int $id, string $extension, int $timeout): ?string {
            if ($this->saveError !== null) {
                return $this->saveError;
            }
            $this->saved[] = [$id, $extension, $timeout];
            $this->core->prepare('UPDATE m_IncomingRoutingTable SET extension = ?, timeout = ? WHERE id = ?')->execute([$extension, $timeout, $id]);

            return null;
        };

        return new RouteLinker($this->module, $this->core, $save, 'admin', self::NOW);
    }

    /** @return array<string, mixed> */
    private function schedule(array $data = []): array
    {
        $editor = new ScheduleEditor($this->module, $this->core, 'admin', self::NOW);
        $id = $editor->createSchedule($data + ['name' => 'Service line', 'number' => '2202', 'template' => 'shift']);

        return (new ScheduleRepository($this->module))->schedule($id);
    }

    private function route(int $id): array
    {
        return $this->core->query("SELECT extension, timeout FROM m_IncomingRoutingTable WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
    }

    public function testLinkPointsTheRouteAtTheScheduleAndRaisesItsTimeout(): void
    {
        $schedule = $this->schedule(['backup_type' => 'member', 'backup_user_id' => 111, 'backup_ring_seconds' => 15]);
        $this->linker()->link($schedule, 3);

        // 20 s on duty + 15 s backup + margin, more than the route's 20 s.
        self::assertSame(['extension' => '2202', 'timeout' => 20 + 15 + RouteLinker::TIMEOUT_MARGIN], $this->route(3));
        $routes = array_column($this->linker()->routes(), null, 'id');
        self::assertSame($schedule['id'], $routes[3]['linked_schedule_id']);
        self::assertFalse($routes[3]['changed_outside']);
        self::assertArrayNotHasKey(1, $routes);
    }

    public function testTheLongestRuleBackupCountsToo(): void
    {
        $schedule = $this->schedule(['backup_type' => 'member', 'backup_user_id' => 111, 'backup_ring_seconds' => 15]);
        $roster = (new ScheduleRepository($this->module))->rosters($schedule['id'])[0]['id'];
        (new ScheduleEditor($this->module, $this->core, 'admin', self::NOW))->setRuleBackup($schedule['id'],
            ['rule' => 'roster', 'rule_id' => $roster, 'backup_type' => 'queue', 'backup_number' => '2000']);

        self::assertSame(20 + 60 + RouteLinker::TIMEOUT_MARGIN, $this->linker()->neededTimeout($schedule));
    }

    public function testQueueBackupCountsTheQueueWait(): void
    {
        $schedule = $this->schedule(['backup_type' => 'queue', 'backup_number' => '2000']);

        self::assertSame(20 + 60 + RouteLinker::TIMEOUT_MARGIN, $this->linker()->neededTimeout($schedule));
    }

    public function testLongerRouteTimeoutIsKept(): void
    {
        $this->core->exec('UPDATE m_IncomingRoutingTable SET timeout = 300 WHERE id = 2');
        $this->linker()->link($this->schedule(), 2);

        self::assertSame(300, $this->route(2)['timeout']);
    }

    public function testUnlinkRestoresDestinationAndTimeout(): void
    {
        $schedule = $this->schedule();
        $this->linker()->link($schedule, 3);

        self::assertTrue($this->linker()->unlink($schedule['id'], 3));
        self::assertSame(['extension' => '201', 'timeout' => 20], $this->route(3));
        self::assertNull(array_column($this->linker()->routes(), null, 'id')[3]['linked_schedule_id']);
        self::assertSame(['schedule.create', 'route.link', 'route.unlink'], $this->module->query('SELECT action FROM m_Audit ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testRouteChangedByHandIsNotOverwritten(): void
    {
        $schedule = $this->schedule();
        $this->linker()->link($schedule, 3);
        $this->core->exec("UPDATE m_IncomingRoutingTable SET extension = '202' WHERE id = 3");

        self::assertTrue(array_column($this->linker()->routes(), null, 'id')[3]['changed_outside']);
        self::assertFalse($this->linker()->unlink($schedule['id'], 3));
        self::assertSame('202', $this->route(3)['extension']);
        self::assertCount(1, $this->saved);
    }

    public function testRouteOfAnotherScheduleIsRefused(): void
    {
        $this->linker()->link($this->schedule(), 3);
        $other = $this->schedule(['name' => 'Other', 'number' => '2203']);

        $this->expectException(EditorException::class);
        $this->expectExceptionMessageMatches('/^occ_ErrRouteLinkedElsewhere/');
        $this->linker()->link($other, 3);
    }

    public function testDefaultActionCannotBeLinked(): void
    {
        $this->expectException(EditorException::class);
        $this->linker()->link($this->schedule(), 1);
    }

    public function testFailedCoreSaveRecordsNothing(): void
    {
        $this->saveError = 'Extension not found';
        try {
            $this->linker()->link($this->schedule(), 3);
            self::fail('Link recorded although the core refused it');
        } catch (EditorException $e) {
            self::assertSame('occ_ErrRouteSave', $e->key);
        }
        self::assertSame(0, (int)$this->module->query('SELECT COUNT(*) FROM m_RouteLinks')->fetchColumn());
    }

    public function testUnlinkAll(): void
    {
        $schedule = $this->schedule();
        $this->linker()->link($schedule, 2);
        $this->linker()->link($schedule, 3);

        self::assertSame(2, $this->linker()->unlinkAll($schedule['id']));
        self::assertSame('2000', $this->route(2)['extension']);
        self::assertSame('201', $this->route(3)['extension']);
    }

    public function testRouteAlreadySentHereByHandIsNotLinked(): void
    {
        $schedule = $this->schedule();
        $this->core->exec("UPDATE m_IncomingRoutingTable SET extension = '2202' WHERE id = 3");

        // Unlinking could only "restore" it to the schedule's own number.
        $this->expectException(EditorException::class);
        $this->expectExceptionMessageMatches('/^occ_ErrRouteAlreadyHere/');
        $this->linker()->link($schedule, 3);
    }

    public function testUnlinkKeepsATimeoutChangedByHand(): void
    {
        $schedule = $this->schedule();
        $this->linker()->link($schedule, 3);
        $this->core->exec('UPDATE m_IncomingRoutingTable SET timeout = 120 WHERE id = 3');

        self::assertTrue($this->linker()->unlink($schedule['id'], 3));
        self::assertSame(['extension' => '201', 'timeout' => 120], $this->route(3));
    }

    public function testUnlimitedRouteTimeoutStaysUnlimited(): void
    {
        $this->core->exec('UPDATE m_IncomingRoutingTable SET timeout = 0 WHERE id = 3');
        $this->linker()->link($this->schedule(), 3);

        self::assertSame(0, $this->route(3)['timeout']);
    }

    public function testRefreshRaisesLinkedTimeoutsAfterTheChainGrows(): void
    {
        $schedule = $this->schedule();
        $this->linker()->link($schedule, 3);
        self::assertSame(20 + RouteLinker::TIMEOUT_MARGIN, $this->route(3)['timeout']);

        (new ScheduleEditor($this->module, $this->core, 'admin', self::NOW))->updateSchedule($schedule['id'], ['ring_seconds' => 60]);
        $this->linker()->refreshTimeouts((new ScheduleRepository($this->module))->schedule($schedule['id']));

        self::assertSame(60 + RouteLinker::TIMEOUT_MARGIN, $this->route(3)['timeout']);
        // Unlinking still restores the route's own timeout.
        $this->linker()->unlink($schedule['id'], 3);
        self::assertSame(['extension' => '201', 'timeout' => 20], $this->route(3));
    }

    public function testReferencesIncludeEveryQueueRedirectAndOtherSchedules(): void
    {
        $this->schedule();
        $this->core->exec("INSERT INTO m_CallQueues (extension, name, redirect_to_extension_if_unanswered) VALUES ('2010', 'Unanswered', '2202')");
        $this->core->exec("INSERT INTO m_CallQueues (extension, name, redirect_to_extension_if_repeat_exceeded) VALUES ('2011', 'Repeats', '2202')");
        $this->core->exec("INSERT INTO m_Extensions (number, type) VALUES ('2202', 'MODULES'), ('2203', 'MODULES'), ('2204', 'MODULES')");
        $editor = new ScheduleEditor($this->module, $this->core, 'admin', self::NOW);
        $this->module->exec("INSERT INTO m_Schedules (name, number, ring_seconds, backup_ring_seconds, final_number, enabled)
            VALUES ('Night', '2203', 20, 20, '2202', '1')");
        $evening = $editor->createSchedule(['name' => 'Evening', 'number' => '2205', 'template' => 'blank', 'outside_number' => '2204']);
        $this->module->exec("INSERT INTO m_Overrides (schedule_id, kind, start_at, end_at, destination, created_at)
            VALUES ($evening, 'elsewhere', '2026-11-11 08:00', '2026-11-11 18:00', '2202', 0)");

        $found = array_map(static fn (array $r): string => $r['kind'] . ':' . $r['name'], $this->linker()->references('2202'));
        sort($found);
        self::assertSame(['queue:Repeats', 'queue:Unanswered', 'schedule:Evening', 'schedule:Night'], $found);
    }

    public function testReferencesFindEverythingThatRoutesToTheNumber(): void
    {
        $this->core->exec("UPDATE m_IncomingRoutingTable SET extension = '2202' WHERE id = 3");
        $this->core->exec("INSERT INTO m_OutWorkTimes (extension, description) VALUES ('2202', 'Nights')");
        $this->core->exec("INSERT INTO m_IvrMenu (uniqid, extension, name, timeout_extension) VALUES ('IVR-1', '2003', 'Main menu', '201')");
        $this->core->exec("INSERT INTO m_IvrMenuActions (ivr_menu_id, digits, extension) VALUES ('IVR-1', '2', '2202')");
        $this->core->exec("INSERT INTO m_ExtensionForwardingRights (extension, forwarding) VALUES ('201', '2202')");

        $kinds = array_column($this->linker()->references('2202'), 'name', 'kind');
        self::assertSame(['route' => 'Sales DID', 'offwork' => 'Nights', 'ivr' => 'Main menu', 'forwarding' => '201'], $kinds);
    }
}
