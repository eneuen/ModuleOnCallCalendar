<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Models;

use MikoPBX\Common\Models\Extensions;
use MikoPBX\Modules\Models\ModulesModelsBase;
use Phalcon\Mvc\Model\Relation;

/**
 * People taking turns: members is a comma list of member IDs in order;
 * turns of turn_days days start at first_handover ("Y-m-d H:i", PBX time).
 * active_hours is a JSON list of daily windows ({weekdays, start, end});
 * empty means the whole turn.
 *
 * The core creates the table from these annotations; the module reads and
 * writes it with plain SQL (ScheduleRepository, ScheduleEditor).
 *
 * @Indexes(
 *     [name='schedule', columns=['schedule_id'], type='']
 * )
 */
class Rotation extends ModulesModelsBase
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
    public $members;

    /**
     * @Column(type="string", nullable=false)
     */
    public $first_handover;

    /**
     * @Column(type="integer", nullable=false)
     */
    public $turn_days;

    /**
     * @Column(type="string", nullable=true)
     */
    public $active_hours;

    /**
     * @Column(type="string", nullable=true)
     */
    public $valid_until;

    /**
     * This rule's own backup, used instead of the schedule's while the rule
     * decides who is on duty: empty (the schedule's backup), none, member
     * (backup_user_id, rung for backup_ring_seconds) or queue (backup_number).
     *
     * @Column(type="string", nullable=true)
     */
    public $backup_type = '';

    /**
     * @Column(type="string", nullable=true)
     */
    public $backup_number = '';

    /**
     * @Column(type="integer", nullable=true)
     */
    public $backup_user_id;

    /**
     * @Column(type="integer", nullable=true)
     */
    public $backup_ring_seconds;

    public function initialize(): void
    {
        $this->setSource('m_Rotations');
        parent::initialize();
    }

    /**
     * The core refuses to delete a queue that this rule's backup still uses.
     */
    public static function getDynamicRelations(&$calledModelObject): void
    {
        if ($calledModelObject instanceof Extensions) {
            $calledModelObject->hasMany('number', self::class, 'backup_number', [
                'alias'      => 'ModuleOnCallCalendarRotationBackup',
                'foreignKey' => [
                    'allowNulls' => true,
                    'message'    => 'Models\\ModuleOnCallCalendarRotationBackup',
                    'action'     => Relation::ACTION_RESTRICT,
                ],
            ]);
        }
    }
}
