<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * A named daily time window on chosen weekdays: a roster's rows. Times are
 * "HH:MM" in PBX time; weekdays a comma list, 1 = Monday. Where shift types
 * overlap, the one lower in the list (higher sort) wins.
 *
 * The core creates the table from these annotations; the module reads and
 * writes it with plain SQL (ScheduleRepository, ScheduleEditor).
 *
 * @Indexes(
 *     [name='schedule', columns=['schedule_id'], type='']
 * )
 */
class ShiftType extends ModulesModelsBase
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
    public $name;

    /**
     * @Column(type="string", nullable=false)
     */
    public $start_time;

    /**
     * @Column(type="string", nullable=false)
     */
    public $end_time;

    /**
     * @Column(type="string", nullable=false)
     */
    public $weekdays;

    /**
     * @Column(type="integer", nullable=false)
     */
    public $sort;

    public function initialize(): void
    {
        $this->setSource('m_ShiftTypes');
        parent::initialize();
    }
}
