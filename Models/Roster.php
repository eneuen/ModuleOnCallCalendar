<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * A weekly roster: repeats over cycle_weeks (1-4) weeks from valid_from,
 * until valid_until when set (dates "Y-m-d"). Priority orders a schedule's
 * rosters and rotations: the lowest number wins where they overlap.
 *
 * The core creates the table from these annotations; the module reads and
 * writes it with plain SQL (ScheduleRepository, ScheduleEditor).
 *
 * @Indexes(
 *     [name='schedule', columns=['schedule_id'], type='']
 * )
 */
class Roster extends ModulesModelsBase
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
     * @Column(type="integer", nullable=false)
     */
    public $priority;

    /**
     * @Column(type="string", nullable=false)
     */
    public $valid_from;

    /**
     * @Column(type="string", nullable=true)
     */
    public $valid_until;

    /**
     * @Column(type="integer", nullable=false)
     */
    public $cycle_weeks;

    public function initialize(): void
    {
        $this->setSource('m_Rosters');
        parent::initialize();
    }
}
