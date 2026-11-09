<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * A person who can be put on duty in a schedule: a MikoPBX employee (user_id
 * in the core's m_Users). Their extension is looked up when it is needed, so
 * a changed number is followed without re-planning.
 *
 * The core creates the table from these annotations; the module reads and
 * writes it with plain SQL (ScheduleRepository, ScheduleEditor).
 *
 * @Indexes(
 *     [name='schedule', columns=['schedule_id'], type='']
 * )
 */
class Member extends ModulesModelsBase
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
    public $user_id;

    /**
     * @Column(type="string", nullable=true)
     */
    public $colour;

    public function initialize(): void
    {
        $this->setSource('m_Members');
        parent::initialize();
    }
}
