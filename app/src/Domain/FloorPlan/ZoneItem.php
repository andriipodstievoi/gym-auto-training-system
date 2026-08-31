<?php

declare(strict_types=1);

namespace App\Domain\FloorPlan;

use App\Domain\Enum\EquipmentType;

/**
 * One line of a zone's inventory, on its way into the detailed plan.
 *
 * The label arrives already resolved into the reader's locale, which keeps
 * {@see ZoneLayoutBuilder} free of translation concerns.
 */
final readonly class ZoneItem
{
    public function __construct(
        public string $key,
        public string $label,
        public EquipmentType $type,
        public int $quantity,
    ) {
    }
}
