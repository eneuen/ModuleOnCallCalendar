<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Models;

use MikoPBX\Common\Models\Extensions;
use MikoPBX\Modules\Models\ModulesModelsBase;
use Phalcon\Mvc\Model\Relation;

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
        $this->setSource('m_Rosters');
        parent::initialize();
    }

    /**
     * The core refuses to delete a queue that this rule's backup still uses.
     */
    public static function getDynamicRelations(&$calledModelObject): void
    {
        if ($calledModelObject instanceof Extensions) {
            $calledModelObject->hasMany('number', self::class, 'backup_number', [
                'alias'      => 'ModuleOnCallCalendarRosterBackup',
                'foreignKey' => [
                    'allowNulls' => true,
                    'message'    => 'Models\\ModuleOnCallCalendarRosterBackup',
                    'action'     => Relation::ACTION_RESTRICT,
                ],
            ]);
        }
    }
}
