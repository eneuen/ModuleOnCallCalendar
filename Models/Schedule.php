<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Models;

use MikoPBX\Common\Models\Extensions;
use MikoPBX\Modules\Models\ModulesModelsBase;
use Phalcon\Mvc\Model\Relation;

/**
 * One schedule: an answerable line with its own internal number, and what
 * happens when the person on duty doesn't answer.
 *
 * The if-no-answer chain has a fixed shape, so it lives on this row:
 *   1. the person or people on duty, for ring_seconds;
 *   2. an optional backup: a person (backup_user_id, an employee, rung for
 *      backup_ring_seconds) or a queue (backup_number; the call is handed
 *      over, and the queue's own timeout and if-empty settings decide what
 *      follows);
 *   3. the final destination, any MikoPBX number (default: voicemail).
 * Outside covered hours the call goes to outside_number, or to the final
 * destination when that is empty.
 *
 * The core creates the table from these annotations; the module reads and
 * writes it with plain SQL (ScheduleRepository, ScheduleEditor).
 *
 * @Indexes(
 *     [name='unique_number', columns=['number'], type='UNIQUE']
 * )
 */
class Schedule extends ModulesModelsBase
{
    public const string BACKUP_NONE = '';
    public const string BACKUP_MEMBER = 'member';
    public const string BACKUP_QUEUE = 'queue';

    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * @Column(type="string", nullable=false)
     */
    public $name;

    /**
     * The schedule's internal number, also a row in the core's m_Extensions
     * (type MODULES).
     *
     * @Column(type="string", nullable=false)
     */
    public $number;

    /**
     * @Column(type="integer", nullable=false, default="20")
     */
    public $ring_seconds = 20;

    /**
     * BACKUP_NONE, BACKUP_MEMBER or BACKUP_QUEUE.
     *
     * @Column(type="string", nullable=true)
     */
    public $backup_type = '';

    /**
     * The backup queue's number.
     *
     * @Column(type="string", nullable=true)
     */
    public $backup_number = '';

    /**
     * The backup person: an employee (the core's m_Users id), so a changed
     * extension is followed.
     *
     * @Column(type="integer", nullable=true)
     */
    public $backup_user_id;

    /**
     * @Column(type="integer", nullable=false, default="20")
     */
    public $backup_ring_seconds = 20;

    /**
     * @Column(type="string", nullable=false, default="voicemail")
     */
    public $final_number = 'voicemail';

    /**
     * @Column(type="string", nullable=true)
     */
    public $outside_number = '';

    /**
     * The template the schedule started from: shift, oncall or blank. Only
     * decides defaults and how the calendar opens.
     *
     * @Column(type="string", nullable=true)
     */
    public $template = 'blank';

    /**
     * A sound file (the core's m_SoundFiles id) played to the caller before
     * the person on duty is rung; empty for none.
     *
     * @Column(type="integer", nullable=true)
     */
    public $greeting_sound_id;

    /**
     * @Column(type="string", length=1, nullable=false, default="1")
     */
    public $enabled = '1';

    public function initialize(): void
    {
        $this->setSource('m_Schedules');
        parent::initialize();
    }

    /**
     * Claims the schedule's number in the core's extensions table. The core
     * shows a MODULES extension in its destination pickers (incoming routes,
     * off-work time rules, IVR menus, forwarding) only when an enabled
     * module's model is related to it.
     *
     * The relation restricts: the core refuses to delete the number while
     * its schedule exists, so a schedule can't lose its number behind the
     * module's back. (A cascade would silently delete the schedule's row
     * whenever the number was deleted.) The module deletes the schedule
     * first, then the number.
     */
    public static function getDynamicRelations(&$calledModelObject): void
    {
        if (!$calledModelObject instanceof Extensions) {
            return;
        }
        // The schedule's own number, and the numbers its chain sends calls to:
        // the core then refuses to delete a queue, IVR menu or employee number
        // that a schedule's backup, final or outside-hours step still uses.
        foreach (['number' => '', 'backup_number' => 'Backup', 'final_number' => 'Final', 'outside_number' => 'Outside'] as $field => $suffix) {
            $calledModelObject->hasMany(
                'number',
                self::class,
                $field,
                [
                    'alias'      => "ModuleOnCallCalendarSchedule$suffix",
                    'foreignKey' => [
                        'allowNulls' => true,
                        'message'    => "Models\\ModuleOnCallCalendarSchedule$suffix",
                        'action'     => Relation::ACTION_RESTRICT,
                    ],
                ]
            );
        }
    }
}
