<?php

declare(strict_types=1);

namespace App\Domain\FloorPlan;

/**
 * The geometry behind the clickable plan on a branch page.
 */
final readonly class FloorPlan
{
    public const string VIEW_BOX = '0 0 880 520';

    /**
     * @param list<FloorPlanRoom> $trainingRooms one per floor zone, in zone order
     * @param list<FloorPlanRoom> $serviceRooms  the fixed entrance side of the building
     */
    public function __construct(
        public array $trainingRooms,
        public array $serviceRooms,
    ) {
    }

    public function roomFor(string $svgId): ?FloorPlanRoom
    {
        foreach ($this->trainingRooms as $room) {
            if ($room->svgId === $svgId) {
                return $room;
            }
        }

        return null;
    }
}
