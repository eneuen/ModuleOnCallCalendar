<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Lib;

use Closure;
use MikoPBX\Common\Models\Extensions;
use MikoPBX\Common\Models\IncomingRoutingTable;
use RuntimeException;

/**
 * Keeps each schedule's number registered in the core's extensions table, as
 * a MODULES extension. That reserves the number and, together with
 * Schedule::getDynamicRelations(), makes it a choice in the core's
 * destination pickers.
 */
final class ScheduleNumbers
{
    /**
     * Creates or updates the extension for a schedule's number. The picker
     * shows "Module: <callerid>" without the number, so the name carries it.
     */
    public static function register(string $number, string $name): void
    {
        $extension = Extensions::findFirstByNumber($number);
        if ($extension !== null && $extension->type !== Extensions::TYPE_MODULES) {
            throw new RuntimeException("The number $number is already used by another extension.");
        }
        $extension ??= new Extensions();
        $extension->number = $number;
        $extension->type = Extensions::TYPE_MODULES;
        $extension->callerid = "$name ($number)";
        $extension->userid = null;
        $extension->show_in_phonebook = '1';
        $extension->public_access = '1';
        $extension->is_general_user_number = '0';
        if (!$extension->save()) {
            throw new RuntimeException('The number could not be saved: ' . implode(' ', $extension->getMessages()));
        }
    }

    /**
     * Removes the extension for a schedule's number, once the schedule itself
     * is deleted. The core refuses while an incoming route or an off-work time
     * rule still points at it, and returns its reasons.
     *
     * @return list<string> why it could not be removed; empty on success
     */
    public static function unregister(string $number): array
    {
        $extension = Extensions::findFirst([
            'number = :number: AND type = :type:',
            'bind' => ['number' => $number, 'type' => Extensions::TYPE_MODULES],
        ]);
        if ($extension === null || $extension->delete()) {
            return [];
        }

        return array_map('strval', $extension->getMessages());
    }

    /**
     * Saves an incoming route's destination and timeout through the core's
     * model, for RouteLinker: the model checks that the destination exists,
     * and its save triggers the dialplan reload. (The REST save action needs
     * services that only exist in the core's API worker.)
     *
     * @return Closure(int, string, int): ?string route id, destination, timeout → error, or null when saved
     */
    public static function routeSaver(): Closure
    {
        return static function (int $routeId, string $extension, int $timeout): ?string {
            $route = IncomingRoutingTable::findFirstById($routeId);
            if ($route === null) {
                return 'not found';
            }
            $route->extension = $extension;
            $route->timeout = (string)$timeout;

            return $route->save() ? null : (implode(' ', array_map('strval', $route->getMessages())) ?: 'unknown error');
        };
    }
}
