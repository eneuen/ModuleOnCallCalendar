<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * One change, for the audit log: who, when, which schedule, and the values
 * before and after (JSON).
 *
 * The core creates the table from these annotations; the module reads and
 * writes it with plain SQL (ScheduleRepository, ScheduleEditor).
 *
 * @Indexes(
 *     [name='schedule_time', columns=['schedule_id', 'at'], type='']
 * )
 */
class AuditEntry extends ModulesModelsBase
{
    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * @Column(type="integer", nullable=true)
     */
    public $schedule_id;

    /**
     * @Column(type="string", nullable=true)
     */
    public $actor;

    /**
     * @Column(type="string", nullable=false)
     */
    public $action;

    /**
     * @Column(type="string", nullable=true)
     */
    public $before_json;

    /**
     * @Column(type="string", nullable=true)
     */
    public $after_json;

    /**
     * @Column(type="integer", nullable=false)
     */
    public $at;

    public function initialize(): void
    {
        $this->setSource('m_Audit');
        parent::initialize();
    }
}
