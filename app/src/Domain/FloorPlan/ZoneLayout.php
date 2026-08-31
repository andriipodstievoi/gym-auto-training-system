<?php

declare(strict_types=1);

namespace App\Domain\FloorPlan;

/**
 * The detailed plan of one zone, drawn on the same canvas as the floor
 * overview so the two swap without the drawing jumping about.
 */
final readonly class ZoneLayout
{
    public const string VIEW_BOX = '0 0 880 520';

    /**
     * @param list<MachineGroup> $groups
     */
    public function __construct(public array $groups)
    {
    }

    public function isEmpty(): bool
    {
        return [] === $this->groups;
    }
}
