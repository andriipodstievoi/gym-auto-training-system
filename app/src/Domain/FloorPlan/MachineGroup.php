<?php

declare(strict_types=1);

namespace App\Domain\FloorPlan;

use App\Domain\Enum\EquipmentType;

/**
 * Every unit of one inventory line, drawn as its own bay on the zone plan.
 */
final readonly class MachineGroup
{
    /**
     * @param list<MachinePlacement> $units one per piece, so six racks are six shapes
     */
    public function __construct(
        public string $key,
        public string $label,
        public EquipmentType $type,
        public int $labelX,
        public int $labelY,
        public array $units,
    ) {
    }
}
