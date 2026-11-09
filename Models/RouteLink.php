<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * An incoming route the module pointed at a schedule's number, with what it
 * pointed at before, so unlinking can put it back exactly.
 *
 * The core creates the table from these annotations; the module reads and
 * writes it with plain SQL (ScheduleRepository, ScheduleEditor).
 *
 * @Indexes(
 *     [name='route', columns=['route_id'], type='UNIQUE']
 * )
 */
class RouteLink extends ModulesModelsBase
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
    public $route_id;

    /**
     * @Column(type="string", nullable=true)
     */
    public $previous_extension;

    /**
     * @Column(type="integer", nullable=true)
     */
    public $previous_timeout;

    /**
     * @Column(type="string", nullable=false)
     */
    public $linked_extension;

    /**
     * @Column(type="integer", nullable=false)
     */
    public $linked_at;

    /**
     * @Column(type="string", nullable=true)
     */
    public $linked_by;

    public function initialize(): void
    {
        $this->setSource('m_RouteLinks');
        parent::initialize();
    }
}
