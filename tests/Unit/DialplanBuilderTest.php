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
        self::assertStringContainsString('ExecIf($[${EPOCH} >= 0${DB(OnCallCalendar/7/until)}]?Set(OCC_START=2))', $plan);
        self::assertMatchesRegularExpression(
            '/Set\(__FW_DISABLE=1\)\n\tsame => n,Set\(__FW_DISABLE_INTERNAL=1\)\n\tsame => n,Dial\(\$\{OCC_DIAL\},20\)\n\tsame => n,Set\(__FW_DISABLE=\)/',
            $plan
        );
        self::assertStringContainsString('same => n(final),Goto(internal,voicemail,1)', $plan);
        self::assertStringContainsString('same => n(outside),Goto(internal,voicemail,1)', $plan);
    }

    public function testPersonBackupIsRungForItsOwnTime(): void
    {
        $plan = DialplanBuilder::internal([['backup_type' => 'member', 'backup_number' => '104', 'backup_ring_seconds' => 15] + self::SCHEDULE]);

        self::assertStringContainsString('Dial(Local/104@internal-users/n,15)', $plan);
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

        self::assertStringContainsString("same => n(backup),NoOp(On-call schedule 7: backup)\n\tsame => n(final)", $plan);
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
            'backup_number' => '1&Local/9', 'ring_seconds' => 99999,
        ] + self::SCHEDULE]);

        self::assertStringContainsString('NoOp(On-call schedule 7: Line Hangup)', $plan);
        self::assertStringContainsString('Goto(internal,voicemail,1)', $plan);
        self::assertStringNotContainsString('Local/9', $plan);
        self::assertStringContainsString('Dial(${OCC_DIAL},600)', $plan);
    }

    public function testTransferEntriesForwardIntoInternal(): void
    {
        self::assertSame(
            "exten => 2202,1,Set(__ISTRANSFER=transfer_)\n\tsame => n,Set(__QUEUE_SRC_CHAN=\${EMPTY})\n\tsame => n,Goto(internal,2202,1)\n",
            DialplanBuilder::internalTransfer([self::SCHEDULE])
        );
    }
}
