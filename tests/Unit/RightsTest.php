<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Tests\Unit;

use Modules\ModuleOnCallCalendar\Lib\Rights;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class RightsTest extends TestCase
{
    /** Every action of the page controller belongs to exactly one right, so none can be missed when granting. */
    public function testEveryControllerActionIsInExactlyOneRight(): void
    {
        $source = (string)file_get_contents((new ReflectionClass(Rights::class))->getFileName());
        self::assertNotSame('', $source);
        $controller = (string)file_get_contents(__DIR__ . '/../../App/Controllers/ModuleOnCallCalendarController.php');
        preg_match_all('/public function (\w+)Action\(/', $controller, $m);
        $actions = array_values(array_diff($m[1], ['denied']));
        $rights = [...Rights::VIEW, ...Rights::EDIT_DUTY, ...Rights::EDIT_SETTINGS];

        sort($actions);
        $sorted = $rights;
        sort($sorted);
        self::assertSame($actions, $sorted);
        self::assertSame(count($rights), count(array_unique($rights)));
    }

    public function testWritableListsOnlyChangeActions(): void
    {
        $can = Rights::writable(static fn (string $action): bool => in_array($action, Rights::EDIT_DUTY, true));

        self::assertTrue($can['setCell']);
        self::assertFalse($can['updateSchedule']);
        self::assertArrayNotHasKey('overview', $can);
    }
}
