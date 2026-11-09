<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * A one-off change for an exact time range ("Y-m-d H:i", PBX time) that beats
 * the roster and rotation. kind: cover, swap, takeover (members is a comma
 * list of member IDs) or elsewhere (destination is a number from the
 * schedule's chain). The two halves of a swap share swap_group.
 *
 * The core creates the table from these annotations; the module reads and
 * writes it with plain SQL (ScheduleRepository, ScheduleEditor).
 *
 * @Indexes(
 *     [name='schedule_time', columns=['schedule_id', 'end_at'], type='']
 * )
 */
class Override extends ModulesModelsBase
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
    public $kind;

    /**
     * @Column(type="string", nullable=false)
     */
    public $start_at;

    /**
     * @Column(type="string", nullable=false)
     */
    public $end_at;

    /**
     * @Column(type="string", nullable=true)
     */
    public $members;

    /**
     * @Column(type="string", nullable=true)
     */
    public $destination;

    /**
     * @Column(type="string", nullable=true)
     */
    public $note;

    /**
     * @Column(type="integer", nullable=true)
     */
    public $swap_group;

    /**
     * @Column(type="string", nullable=true)
     */
    public $created_by;

    /**
     * @Column(type="integer", nullable=false)
     */
    public $created_at;

    public function initialize(): void
    {
        $this->setSource('m_Overrides');
        parent::initialize();
    }
}
