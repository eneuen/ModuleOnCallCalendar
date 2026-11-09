<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Lib;

/**
 * Builds the dialplan for the schedules' numbers. Pure PHP: it takes the
 * schedule rows as arrays and returns dialplan text, so it is unit-tested
 * without a PBX.
 *
 * Who is on duty is not in the dialplan. It is published into Asterisk's
 * database (AstDB, family FAMILY) and read when a call arrives, so roster
 * changes never need a dialplan reload:
 *
 *   <id>/dial   the dial string for step 1, e.g. Local/104@internal-users/n
 *   <id>/start  where the call starts: 1 (on duty), 2 (gap: backup), out
 *               (outside covered hours) or go (a "send elsewhere" override)
 *   <id>/goto   for go: the number to send the call to
 *   <id>/until  epoch seconds after which the answer is stale
 *
 * DutyPublisher writes these values. A stale or missing answer is asked
 * again from the AGI script LOOKUP_AGI, which reads the database itself. If
 * that fails too, the call is treated as a gap and goes to the backup and
 * then the final destination, rather than to someone who may no longer be
 * on duty.
 *
 * Each number's entry:
 * - clears the absolute timeout that [internal-incoming] puts on the channel,
 *   so an answered call isn't ended at the route's timeout. The route's own
 *   Dial timeout still limits the unanswered chain, so a linked route's
 *   timeout must be longer than the chain;
 * - clears pt1c_UNIQUEID, so each leg rung from here gets its own call record
 *   (otherwise an internal caller's record for the schedule number is reused
 *   and the person who answered is never recorded);
 * - rings the people on duty through [internal-users], like a normal call
 *   (call records, recording, every registered device, busy handling), but
 *   with FW_DISABLE / FW_DISABLE_INTERNAL set so their personal forwarding
 *   and voicemail can't take the call before the backup gets its turn;
 * - then the backup: a person is rung the same way; a queue gets the call
 *   with Goto, because a queue that plays music answers the call at once and
 *   would make any ring-time limit meaningless;
 * - then the final destination.
 */
final class DialplanBuilder
{
    public const string FAMILY = 'OnCallCalendar';

    public const string LOOKUP_AGI = 'on_call_calendar_lookup.php';

    /**
     * Entries for [internal], one per enabled schedule.
     *
     * @param list<array<string, mixed>> $schedules rows of m_Schedules
     */
    public static function internal(array $schedules): string
    {
        $out = '';
        foreach (self::valid($schedules) as $s) {
            $out .= self::entry($s);
        }

        return $out;
    }

    /**
     * Entries for [internal-transfer], so phones can transfer callers to a
     * schedule's number. Same shape as the core's IVR menus.
     *
     * @param list<array<string, mixed>> $schedules rows of m_Schedules
     */
    public static function internalTransfer(array $schedules): string
    {
        $out = '';
        foreach (self::valid($schedules) as $s) {
            $n = $s['number'];
            $out .= "exten => $n,1,Set(__ISTRANSFER=transfer_)\n\t"
                . "same => n,Set(__QUEUE_SRC_CHAN=\${EMPTY})\n\t"
                . "same => n,Goto(internal,$n,1)\n";
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $s
     */
    private static function entry(array $s): string
    {
        $id = (int)$s['id'];
        $n = (string)$s['number'];
        $key = self::FAMILY . "/$id";
        $ring = self::seconds($s['ring_seconds'] ?? 20);
        $final = self::target((string)($s['final_number'] ?? '')) ?? 'voicemail';
        $outside = self::target((string)($s['outside_number'] ?? '')) ?? $final;
        $name = self::comment((string)($s['name'] ?? ''));

        $lines = [
            "exten => $n,1,NoOp(On-call schedule $id: $name)",
            'same => n,Set(TIMEOUT(absolute)=0)',
            // New call records for the legs rung from here, as the core does before forwarding.
            'same => n,Set(__pt1c_UNIQUEID=${UNDEFINED})',
            "same => n,Set(OCC_START=\${DB($key/start)})",
            "same => n,Set(OCC_DIAL=\${DB($key/dial)})",
            "same => n,Set(OCC_GOTO=\${DB($key/goto)})",
            // A stale or missing answer: ask the AGI fallback; if that fails it stays a gap.
            "same => n,GotoIf(\$[\${EPOCH} < 0\${DB($key/until)}]?fresh)",
            'same => n,Set(OCC_START=2)',
            'same => n,AGI(' . self::LOOKUP_AGI . ",$id)",
            'same => n(fresh),GotoIf($["${OCC_START}" == "out"]?outside)',
            'same => n,GotoIf($["${OCC_START}" == "go" & "${OCC_GOTO}x" != "x"]?elsewhere)',
            'same => n,GotoIf($["${OCC_START}" != "1" | "${OCC_DIAL}x" == "x"]?backup)',
            ...self::ringWithoutForwarding('${OCC_DIAL}', $ring),
            'same => n,GotoIf($["${DIALSTATUS}" == "ANSWER"]?done)',
            'same => n(backup),NoOp(On-call schedule ' . $id . ': backup)',
            ...self::backup($s),
            "same => n(final),Goto(internal,$final,1)",
            "same => n(outside),Goto(internal,$outside,1)",
            'same => n(elsewhere),Goto(internal,${OCC_GOTO},1)',
            'same => n(done),Hangup()',
        ];

        return implode("\n\t", $lines) . "\n";
    }

    /**
     * @param array<string, mixed> $s
     * @return list<string>
     */
    private static function backup(array $s): array
    {
        $number = self::target((string)($s['backup_number'] ?? ''));
        if ($number === null) {
            return [];
        }

        return match ((string)($s['backup_type'] ?? '')) {
            'member' => [
                ...self::ringWithoutForwarding("Local/$number@internal-users/n", self::seconds($s['backup_ring_seconds'] ?? 20)),
                'same => n,GotoIf($["${DIALSTATUS}" == "ANSWER"]?done)',
            ],
            'queue' => ["same => n,Goto(internal,$number,1)"],
            default => [],
        };
    }

    /**
     * Rings through [internal-users] with the employees' own forwarding turned
     * off for this call only. The core checks FW_DISABLE and
     * FW_DISABLE_INTERNAL after its Dial and skips forwarding when both are 1.
     * They are inherited (__) so they reach the far side of the Local channel,
     * and cleared afterwards so later steps behave normally.
     *
     * @return list<string>
     */
    private static function ringWithoutForwarding(string $dial, int $seconds): array
    {
        return [
            'same => n,Set(__FW_DISABLE=1)',
            'same => n,Set(__FW_DISABLE_INTERNAL=1)',
            "same => n,Dial($dial,$seconds)",
            'same => n,Set(__FW_DISABLE=)',
            'same => n,Set(__FW_DISABLE_INTERNAL=)',
        ];
    }

    /**
     * Schedules that can be written: enabled, with a numeric number.
     *
     * @param list<array<string, mixed>> $schedules
     * @return list<array<string, mixed>>
     */
    private static function valid(array $schedules): array
    {
        return array_values(array_filter(
            $schedules,
            static fn (array $s): bool => (string)($s['enabled'] ?? '1') === '1'
                && preg_match('/^\d{2,20}$/', (string)($s['number'] ?? '')) === 1
        ));
    }

    /** A destination number: digits, or one of the core's named system extensions. */
    private static function target(string $number): ?string
    {
        return preg_match('/^(\d{1,20}|voicemail|hangup|busy)$/', $number) === 1 ? $number : null;
    }

    private static function seconds(mixed $value): int
    {
        return max(5, min(600, (int)$value));
    }

    /** Text safe inside NoOp(): no characters that end or split the argument. */
    private static function comment(string $text): string
    {
        return trim((string)preg_replace('/[^\p{L}\p{N} ._-]/u', '', $text));
    }
}
