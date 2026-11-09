<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * One person in one roster cell: a shift type on a weekday of a cycle week.
 * Several rows for one cell ring together.
 *
 * The core creates the table from these annotations; the module reads and
 * writes it with plain SQL (ScheduleRepository, ScheduleEditor).
 *
 * @Indexes(
 *     [name='roster', columns=['roster_id'], type='']
 * )
 */
class RosterCell extends ModulesModelsBase
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
    public $roster_id;

    /**
     * @Column(type="integer", nullable=false)
     */
    public $shift_type_id;

    /**
     * @Column(type="integer", nullable=false)
     */
    public $week;

    /**
     * @Column(type="integer", nullable=false)
     */
    public $weekday;

    /**
     * @Column(type="integer", nullable=false)
     */
    public $member_id;

    public function initialize(): void
    {
        $this->setSource('m_RosterCells');
        parent::initialize();
    }
}
