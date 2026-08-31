<?php

declare(strict_types=1);

namespace App\Domain\FloorPlan;

/**
 * The geometry of one storey, behind the clickable plan on a branch page.
 */
final readonly class FloorPlan
{
    public const string VIEW_BOX = '0 0 880 520';

    /**
     * @param list<FloorPlanRoom> $rooms one per zone on the storey, in zone order
     */
    public function __construct(public array $rooms)
    {
    }

    public function roomFor(string $svgId): ?FloorPlanRoom
    {
        foreach ($this->rooms as $room) {
            if ($room->svgId === $svgId) {
                return $room;
            }
        }

        return null;
    }

    public function isEmpty(): bool
    {
        return [] === $this->rooms;
    }
}
