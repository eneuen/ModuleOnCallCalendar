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
 *   <id>/backup the deciding roster's or rotation's own backup (BackupSpec);
 *               empty for the schedule's backup
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
    public const string TAKEOVER_AGI = 'on_call_calendar_takeover.php';

    /** The feature code for taking over duty from a phone; a schedule's number may follow. */
    public const string TAKEOVER_CODE = '*22';
    public const string TAKEOVER_CONTEXT = 'on-call-calendar-takeover';

    /** How many schedules one call may pass through before it is sent to voicemail. */
    public const int MAX_HOPS = 5;

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
        if ($out !== '') {
            // *22 alone takes over the caller's one schedule; *22 followed by a number picks one.
            $code = self::TAKEOVER_CODE;
            $out .= "exten => $code,1,Set(OCC_TO=)\n\t"
                . 'same => n,Goto(' . self::TAKEOVER_CONTEXT . ",s,1)\n"
                . "exten => _{$code}X.,1,Set(OCC_TO=\${FILTER(0-9,\${EXTEN:" . strlen($code) . "})})\n\t"
                . 'same => n,Goto(' . self::TAKEOVER_CONTEXT . ",s,1)\n";
        }

        return $out;
    }

    /**
     * Separate contexts: the *22 take-over. The caller is identified by the
     * PJSIP endpoint the call came from; the AGI script checks they are a
     * member of the schedule, adds the override and sets OCC_TAKEOVER.
     *
     * @param list<array<string, mixed>> $schedules rows of m_Schedules
     */
    public static function contexts(array $schedules): string
    {
        if (self::valid($schedules) === []) {
            return '';
        }

        return '[' . self::TAKEOVER_CONTEXT . "]\n"
            . "exten => s,1,Answer()\n\t"
            . 'same => n,AGI(' . self::TAKEOVER_AGI . ',${CHANNEL(endpoint)},${OCC_TO})' . "\n\t"
            . 'same => n,ExecIf($["${OCC_TAKEOVER}" == "ok"]?Playback(beep&auth-thankyou):Playback(beeperr))' . "\n\t"
            . "same => n,Hangup()\n";
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
        // After a greeting the call is in early media, so phones play no ringback of their own.
        $ringback = self::greeting($s) !== [];

        $lines = [
            "exten => $n,1,NoOp(On-call schedule $id: $name)",
            // Schedules can send calls to each other; a misconfigured circle ends in voicemail, not a loop.
            'same => n,Set(__OCC_HOPS=$[0${OCC_HOPS} + 1])',
            'same => n,GotoIf($[${OCC_HOPS} > ' . self::MAX_HOPS . ']?loop)',
            'same => n,Set(TIMEOUT(absolute)=0)',
            // New call records for the legs rung from here, as the core does before forwarding.
            'same => n,Set(__pt1c_UNIQUEID=${UNDEFINED})',
            "same => n,Set(OCC_START=\${DB($key/start)})",
            "same => n,Set(OCC_DIAL=\${DB($key/dial)})",
            "same => n,Set(OCC_GOTO=\${DB($key/goto)})",
            "same => n,Set(OCC_BSPEC=\${DB($key/backup)})",
            // A stale or missing answer: ask the AGI fallback; if that fails it stays a gap.
            "same => n,GotoIf(\$[\${EPOCH} < 0\${DB($key/until)}]?fresh)",
            'same => n,Set(OCC_START=2)',
            'same => n,AGI(' . self::LOOKUP_AGI . ",$id)",
            // A note for the activity report; the worker reads the call's records once it is over (CallLog).
            'same => n(fresh),Set(DB(' . CallLog::FAMILY . "/$id/\${CHANNEL(linkedid)})=\${EPOCH}|\${OCC_START}|\${OCC_DIAL})",
            'same => n,GotoIf($["${OCC_START}" == "out"]?outside)',
            'same => n,GotoIf($["${OCC_START}" == "go" & "${OCC_GOTO}x" != "x"]?elsewhere)',
            ...self::callerIdPrefix($s),
            ...self::greeting($s),
            'same => n,GotoIf($["${OCC_START}" != "1" | "${OCC_DIAL}x" == "x"]?backup)',
            ...self::ringWithoutForwarding('OCC_DIAL', $ring, $ringback, 'duty'),
            'same => n,GotoIf($["${DIALSTATUS}" == "ANSWER"]?done)',
            'same => n(backup),NoOp(On-call schedule ' . $id . ': backup)',
            ...self::ruleBackup($ringback),
            'same => n(bdefault),NoOp()',
            ...self::backup($s),
            "same => n(final),Goto(internal,$final,1)",
            "same => n(outside),Goto(internal,$outside,1)",
            'same => n(elsewhere),Goto(internal,${OCC_GOTO},1)',
            "same => n(loop),Log(WARNING,On-call schedule $id: calls go round in a circle of schedules - sent to voicemail)",
            'same => n,Goto(internal,voicemail,1)',
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
        // For a person, the caller resolves backup_user_id to their extension (backup_extension).
        $type = (string)($s['backup_type'] ?? '');
        $number = self::target((string)($type === 'member' ? ($s['backup_extension'] ?? '') : ($s['backup_number'] ?? '')));
        if ($number === null) {
            return [];
        }

        return match ($type) {
            'member' => [
                "same => n,Set(OCC_BACKUP=Local/$number@internal-users/n)",
                ...self::ringWithoutForwarding('OCC_BACKUP', self::seconds($s['backup_ring_seconds'] ?? 20),
                    self::greeting($s) !== [], 'backup'),
                'same => n,GotoIf($["${DIALSTATUS}" == "ANSWER"]?done)',
            ],
            'queue' => ["same => n,Goto(internal,$number,1)"],
            default => [],
        };
    }

    /**
     * The deciding rule's own backup, read from AstDB with the rest of the
     * answer (OCC_BSPEC, see BackupSpec): none goes straight to the final
     * destination, queue|<n> hands the call to the queue, dial|<s>|<legs>
     * rings that person for s seconds. Empty falls through to the
     * schedule's own backup (bdefault). The worker validates the spec; the
     * queue number is checked again here.
     *
     * @return list<string>
     */
    private static function ruleBackup(bool $ringback): array
    {
        return [
            'same => n,GotoIf($["${OCC_BSPEC}x" == "x"]?bdefault)',
            'same => n,GotoIf($["${OCC_BSPEC}" == "none"]?final)',
            'same => n,Set(OCC_BKIND=${CUT(OCC_BSPEC,|,1)})',
            'same => n,GotoIf($["${OCC_BKIND}" == "queue" & ${REGEX("^[0-9]+$" ${CUT(OCC_BSPEC,|,2)})}]?bqueue)',
            'same => n,GotoIf($["${OCC_BKIND}" != "dial"]?bdefault)',
            'same => n,Set(OCC_BRING=${CUT(OCC_BSPEC,|,2)})',
            'same => n,Set(OCC_BACKUP=${CUT(OCC_BSPEC,|,3)})',
            ...self::ringWithoutForwarding('OCC_BACKUP', '${OCC_BRING}', $ringback, 'rule'),
            'same => n,GotoIf($["${DIALSTATUS}" == "ANSWER"]?done)',
            'same => n,Goto(final)',
            'same => n(bqueue),Goto(internal,${CUT(OCC_BSPEC,|,2)},1)',
        ];
    }

    /**
     * Rings the dial string in channel variable $dialVar (Local/<ext>@internal-users/n
     * legs joined by &) through [internal-users], with each employee's own
     * forwarding turned off for this call only:
     * - FW_DISABLE and FW_DISABLE_INTERNAL make the core skip forwarding
     *   after its Dial (it checks both).
     * - FW_TIME_<ext> is the employee's own "forward after N seconds"; the
     *   core uses it as the phone's ring time, which would cut this step
     *   short. A channel variable shadows the global, so it is set to the
     *   core's own no-forwarding value (600) for each leg; this step's Dial
     *   timeout decides.
     * All are inherited (__) so they reach the far side of the Local channel.
     * Afterwards the caller's channel gets them back: forwarding on, and each
     * FW_TIME_<ext> its own value again. Asterisk can't remove a channel
     * variable (an empty one still hides the global) and MikoPBX has no
     * GLOBAL(), so each value is saved first as OCC_FWT_<ext>, with an "x" in
     * front so that a saved empty value differs from none saved. OCC_FWOFF
     * marks the legs, so a call that the person who answered transfers gets
     * forwarding back (internalUsersPreDial).
     *
     * @return list<string>
     */
    private static function ringWithoutForwarding(string $dialVar, int|string $seconds, bool $ringback, string $label): array
    {
        return [
            'same => n,Set(__FW_DISABLE=1)',
            'same => n,Set(__FW_DISABLE_INTERNAL=1)',
            'same => n,Set(__OCC_FWOFF=1)',
            ...self::eachExtension($dialVar, "{$label}save", 'Set(__OCC_FWT_${OCC_F}=x${FW_TIME_${OCC_F}})'),
            ...self::eachExtension($dialVar, "{$label}set", 'Set(__FW_TIME_${OCC_F}=600)'),
            "same => n,Dial(\${{$dialVar}},$seconds" . ($ringback ? ',r' : '') . ')',
            'same => n,Set(__FW_DISABLE=)',
            'same => n,Set(__FW_DISABLE_INTERNAL=)',
            'same => n,Set(__OCC_FWOFF=)',
            ...self::eachExtension($dialVar, "{$label}clear", 'Set(__FW_TIME_${OCC_F}=${OCC_FWT_${OCC_F}:1})'),
        ];
    }

    /**
     * For [internal-users], before the core works out an employee's ring time
     * and forwarding: a call the module rang (OCC_FWOFF) that the person who
     * answered has transferred is an ordinary call again, so the transfer
     * target's own forwarding and voicemail apply. Without this the legs'
     * inherited "forwarding off" would follow the caller to the target. When
     * the target was rung in that step too, its own ring time is put back
     * from what was saved (see ringWithoutForwarding).
     */
    public static function internalUsersPreDial(): string
    {
        $when = '"${ISTRANSFER}x" != "x" & "${OCC_FWOFF}" == "1"';

        return implode("\n\t", [
            "same => n,ExecIf(\$[$when]?Set(__FW_DISABLE=))",
            "same => n,ExecIf(\$[$when]?Set(__FW_DISABLE_INTERNAL=))",
            "same => n,ExecIf(\$[$when & \"\${OCC_FWT_\${EXTEN}}x\" != \"x\"]?Set(__FW_TIME_\${EXTEN}=\${OCC_FWT_\${EXTEN}:1}))",
            "same => n,ExecIf(\$[$when]?Set(__OCC_FWOFF=))",
        ]) . "\n\t";
    }

    /**
     * Runs $app once for every extension in a dial string (Local/<ext>@...
     * legs joined by &), with the extension in OCC_F; anything that isn't
     * a plain number is skipped. The dial string is read when the call
     * arrives, so the loop is in the dialplan.
     *
     * @return list<string>
     */
    private static function eachExtension(string $dialVar, string $label, string $app): array
    {
        return [
            'same => n,Set(OCC_I=0)',
            "same => n($label),Set(OCC_I=\$[\${OCC_I} + 1])",
            "same => n,GotoIf(\$[\${OCC_I} > \${FIELDQTY($dialVar,&)}]?{$label}done)",
            "same => n,Set(OCC_F=\${CUT($dialVar,&,\${OCC_I})})",
            'same => n,Set(OCC_F=${CUT(OCC_F,/,2)})',
            'same => n,Set(OCC_F=${CUT(OCC_F,@,1)})',
            "same => n,ExecIf(\$[\${REGEX(\"^[0-9]+\$\" \${OCC_F})}]?$app)",
            "same => n,Goto($label)",
            "same => n({$label}done),NoOp()",
        ];
    }

    /**
     * The greeting, played to the caller before anyone is rung, as early
     * media: Progress() opens the audio path without answering the call, so
     * the caller isn't charged and the route still sees an unanswered call.
     * The caller resolves greeting_sound_id to greeting_path (a sound file
     * path without extension); anything else is left out.
     *
     * @param array<string, mixed> $s
     * @return list<string>
     */
    private static function greeting(array $s): array
    {
        $path = (string)($s['greeting_path'] ?? '');
        if (preg_match('#^/[\w/.-]+$#', $path) !== 1) {
            return [];
        }

        return ['same => n,Progress()', "same => n,Playback($path,noanswer)"];
    }

    /**
     * Puts the schedule's prefix before the caller's name for the people it
     * rings, as the core's queues do ("SERVICE:John"). Only letters, digits
     * and spaces reach the dialplan.
     *
     * @param array<string, mixed> $s
     * @return list<string>
     */
    private static function callerIdPrefix(array $s): array
    {
        $prefix = trim((string)preg_replace('/[^\p{L}\p{N} ]/u', '', (string)($s['callerid_prefix'] ?? '')));

        return $prefix === '' ? [] : ['same => n,Set(CALLERID(name)=' . $prefix . ':${CALLERID(name)})'];
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
