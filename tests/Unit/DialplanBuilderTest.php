<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Tests\Unit;

use Modules\ModuleOnCallCalendar\Lib\DialplanBuilder;
use PHPUnit\Framework\TestCase;

final class DialplanBuilderTest extends TestCase
{
    private const array SCHEDULE = [
        'id' => 7, 'name' => 'Service line', 'number' => '2202', 'ring_seconds' => 20,
        'backup_type' => '', 'backup_number' => '', 'backup_ring_seconds' => 20,
        'final_number' => 'voicemail', 'outside_number' => '', 'enabled' => '1',
    ];

    public function testEntryReadsTheAnswerFromAstdbAndRingsWithoutForwarding(): void
    {
        $plan = DialplanBuilder::internal([self::SCHEDULE]);

        self::assertStringStartsWith('exten => 2202,1,NoOp(On-call schedule 7: Service line)', $plan);
        self::assertStringContainsString('Set(TIMEOUT(absolute)=0)', $plan);
        self::assertStringContainsString('Set(__pt1c_UNIQUEID=${UNDEFINED})', $plan);
        self::assertStringContainsString('Set(OCC_DIAL=${DB(OnCallCalendar/7/dial)})', $plan);
        self::assertStringContainsString(
            "GotoIf(\$[\${EPOCH} < 0\${DB(OnCallCalendar/7/until)}]?fresh)\n\tsame => n,Set(OCC_START=2)\n\tsame => n,AGI(on_call_calendar_lookup.php,7)\n\tsame => n(fresh),",
            $plan
        );
        self::assertStringContainsString('GotoIf($["${OCC_START}" == "go" & "${OCC_GOTO}x" != "x"]?elsewhere)', $plan);
        self::assertStringContainsString('same => n(elsewhere),Goto(internal,${OCC_GOTO},1)', $plan);
        self::assertMatchesRegularExpression(
            '/Set\(__FW_DISABLE=1\)\n\tsame => n,Set\(__FW_DISABLE_INTERNAL=1\)\n.*Set\(__FW_TIME_\$\{OCC_F\}=600\).*\n\tsame => n,Dial\(\$\{OCC_DIAL\},20\)\n\tsame => n,Set\(__FW_DISABLE=\)/s',
            $plan
        );
        // Each on-duty person's own forward timer is shadowed for this call, then cleared.
        self::assertStringContainsString('Set(OCC_F=${CUT(OCC_DIAL,&,${OCC_I})})', $plan);
        self::assertStringContainsString('ExecIf($[${REGEX("^[0-9]+$" ${OCC_F})}]?Set(__FW_TIME_${OCC_F}=))', $plan);
        // A circle of schedules ends in voicemail; no ';' (a comment in extensions.conf) in the warning.
        self::assertStringContainsString('Set(__OCC_HOPS=$[0${OCC_HOPS} + 1])', $plan);
        self::assertStringContainsString('GotoIf($[${OCC_HOPS} > 5]?loop)', $plan);
        self::assertStringNotContainsString(';', $plan);
        self::assertStringContainsString('same => n(final),Goto(internal,voicemail,1)', $plan);
        self::assertStringContainsString('same => n(outside),Goto(internal,voicemail,1)', $plan);
    }

    public function testPersonBackupIsRungForItsOwnTime(): void
    {
        $plan = DialplanBuilder::internal([['backup_type' => 'member', 'backup_extension' => '104', 'backup_ring_seconds' => 15] + self::SCHEDULE]);

        self::assertStringContainsString('Set(OCC_BACKUP=Local/104@internal-users/n)', $plan);
        self::assertStringContainsString('Dial(${OCC_BACKUP},15)', $plan);
        self::assertStringContainsString('Set(OCC_F=${CUT(OCC_BACKUP,&,${OCC_I})})', $plan);
    }

    public function testQueueBackupGetsTheCall(): void
    {
        $plan = DialplanBuilder::internal([['backup_type' => 'queue', 'backup_number' => '2000'] + self::SCHEDULE]);

        self::assertStringContainsString('same => n,Goto(internal,2000,1)', $plan);
        self::assertStringNotContainsString('Dial(Local/2000', $plan);
    }

    public function testNoBackupFallsThroughToTheFinalDestination(): void
    {
        $plan = DialplanBuilder::internal([self::SCHEDULE]);

        // No rule backup and no schedule backup: straight on to the final destination.
        self::assertStringContainsString("same => n(bdefault),NoOp()\n\tsame => n(final)", $plan);
    }

    public function testOutsideHoursDestinationOverridesTheFinalOne(): void
    {
        $plan = DialplanBuilder::internal([['outside_number' => '2003'] + self::SCHEDULE]);

        self::assertStringContainsString('same => n(outside),Goto(internal,2003,1)', $plan);
    }

    public function testDisabledAndMalformedSchedulesAreLeftOut(): void
    {
        self::assertSame('', DialplanBuilder::internal([
            ['enabled' => '0'] + self::SCHEDULE,
            ['number' => '22;02'] + self::SCHEDULE,
        ]));
    }

    public function testUnsafeValuesAreNotWritten(): void
    {
        $plan = DialplanBuilder::internal([[
            'name' => 'Line), Hangup(', 'final_number' => 'voicemail,1)', 'backup_type' => 'member',
            'backup_extension' => '1&Local/9', 'ring_seconds' => 99999,
        ] + self::SCHEDULE]);

        self::assertStringContainsString('NoOp(On-call schedule 7: Line Hangup)', $plan);
        self::assertStringContainsString('Goto(internal,voicemail,1)', $plan);
        self::assertStringNotContainsString('Local/9', $plan);
        self::assertStringContainsString('Dial(${OCC_DIAL},600)', $plan);
    }

    public function testGreetingPlaysAsEarlyMediaThenRingsWithRingback(): void
    {
        $plan = DialplanBuilder::internal([['greeting_path' => '/offload/asterisk/sounds/other/oncall', 'backup_type' => 'member',
            'backup_extension' => '104'] + self::SCHEDULE]);

        self::assertStringContainsString(
            "same => n,Progress()\n\tsame => n,Playback(/offload/asterisk/sounds/other/oncall,noanswer)\n\tsame => n,GotoIf(\$[\"\${OCC_START}\" != \"1\"",
            $plan
        );
        self::assertStringContainsString('Dial(${OCC_DIAL},20,r)', $plan);
        self::assertStringContainsString('Dial(${OCC_BACKUP},20,r)', $plan);
    }

    public function testWithoutGreetingNoEarlyMedia(): void
    {
        $plan = DialplanBuilder::internal([['greeting_path' => 'x,1)Hangup('] + self::SCHEDULE]);

        self::assertStringNotContainsString('Playback', $plan);
        self::assertStringNotContainsString('Progress', $plan);
        self::assertStringContainsString('Dial(${OCC_DIAL},20)', $plan);
    }

    public function testRuleBackupFromAstdbComesBeforeTheSchedulesOwn(): void
    {
        $plan = DialplanBuilder::internal([['backup_type' => 'queue', 'backup_number' => '2000'] + self::SCHEDULE]);

        self::assertStringContainsString('Set(OCC_BSPEC=${DB(OnCallCalendar/7/backup)})', $plan);
        self::assertStringContainsString('GotoIf($["${OCC_BSPEC}" == "none"]?final)', $plan);
        self::assertStringContainsString('same => n(bqueue),Goto(internal,${CUT(OCC_BSPEC,|,2)},1)', $plan);
        self::assertStringContainsString('Dial(${OCC_BACKUP},${OCC_BRING})', $plan);
        // The schedule's own backup follows bdefault.
        self::assertStringContainsString("same => n(bdefault),NoOp()\n\tsame => n,Goto(internal,2000,1)", $plan);
    }

    public function testCallerIdPrefixForThePeopleRung(): void
    {
        $plan = DialplanBuilder::internal([['callerid_prefix' => 'Сервис 2, ok)'] + self::SCHEDULE]);

        self::assertStringContainsString(
            "GotoIf(\$[\"\${OCC_START}\" == \"go\" & \"\${OCC_GOTO}x\" != \"x\"]?elsewhere)\n\tsame => n,Set(CALLERID(name)=Сервис 2 ok:\${CALLERID(name)})",
            $plan
        );
        self::assertStringNotContainsString('CALLERID(name)', DialplanBuilder::internal([self::SCHEDULE]));
    }

    public function testTransferEntriesForwardIntoInternal(): void
    {
        self::assertSame(
            "exten => 2202,1,Set(__ISTRANSFER=transfer_)\n\tsame => n,Set(__QUEUE_SRC_CHAN=\${EMPTY})\n\tsame => n,Goto(internal,2202,1)\n",
            DialplanBuilder::internalTransfer([self::SCHEDULE])
        );
    }
}
