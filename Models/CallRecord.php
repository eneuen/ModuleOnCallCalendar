<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * One call that reached a schedule, for the activity report: when, how the
 * schedule decided (start: 1 on duty, 2 gap, out, go), who was on duty
 * (on_duty, a comma list of extensions), and how it ended (outcome: on_duty,
 * other, voicemail or unanswered), with who answered and after how long.
 *
 * The core creates the table from these annotations; the worker fills it
 * from the PBX's call records once the call is over (Lib/CallLog).
 *
 * @Indexes(
 *     [name='schedule_call', columns=['schedule_id', 'linkedid'], type='UNIQUE'],
 *     [name='schedule_time', columns=['schedule_id', 'started_at'], type='']
 * )
 */
class CallRecord extends ModulesModelsBase
{
    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * @Column(type="integer", nullable=false)
     */
    public $schedule_id;

    /**
     * @Column(type="string", nullable=false)
     */
    public $linkedid;

    /**
     * @Column(type="integer", nullable=false)
     */
    public $started_at;

    /**
     * @Column(type="string", nullable=false)
     */
    public $start;

    /**
     * @Column(type="string", nullable=true)
     */
    public $on_duty;

    /**
     * @Column(type="string", nullable=false)
     */
    public $outcome;

    /**
     * @Column(type="string", nullable=true)
     */
    public $answered_by;

    /**
     * @Column(type="integer", nullable=true)
     */
    public $wait_seconds;

    public function initialize(): void
    {
        $this->setSource('m_Calls');
        parent::initialize();
    }
}
