<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleOnCallCalendar\Lib;

/**
 * One stretch of time with a single decision: from $from up to (not
 * including) $to, calls to the schedule go as $start says.
 *
 * - START_ON_DUTY: ring $targets (extensions) together, then the chain.
 * - START_GAP: covered time with nobody to ring: the backup, then final.
 * - START_OUTSIDE: outside covered hours: the outside-hours destination.
 * - START_ELSEWHERE: a "send elsewhere" override: $destination.
 *
 * $memberIds lists who was planned, including people whose extension is
 * gone (they are left out of $targets).
 */
final readonly class DutySegment
{
    /**
     * @param list<int> $memberIds
     * @param list<string> $targets
     */
    public function __construct(
        public int $from,
        public int $to,
        public string $start,
        public string $decidedBy,
        public int $sourceId,
        public array $memberIds = [],
        public array $targets = [],
        public string $destination = '',
    ) {
    }

    public function sameDecisionAs(self $other): bool
    {
        return $this->start === $other->start
            && $this->decidedBy === $other->decidedBy
            && $this->sourceId === $other->sourceId
            && $this->memberIds === $other->memberIds
            && $this->targets === $other->targets
            && $this->destination === $other->destination;
    }

    public function extendedTo(int $to): self
    {
        return new self($this->from, $to, $this->start, $this->decidedBy, $this->sourceId, $this->memberIds, $this->targets, $this->destination);
    }

    /** The step-1 dial string: every target through the core's extension dialplan. */
    public function dialString(): string
    {
        return implode('&', array_map(static fn (string $ext): string => "Local/$ext@internal-users/n", $this->targets));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'from' => $this->from, 'to' => $this->to, 'start' => $this->start, 'decided_by' => $this->decidedBy,
            'source_id' => $this->sourceId, 'member_ids' => $this->memberIds, 'targets' => $this->targets,
            'destination' => $this->destination,
        ];
    }
}
