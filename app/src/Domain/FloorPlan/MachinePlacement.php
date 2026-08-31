<?php

declare(strict_types=1);

namespace App\Domain\FloorPlan;

/**
 * The footprint of a single machine on the detailed zone plan.
 */
final readonly class MachinePlacement
{
    public function __construct(
        public int $x,
        public int $y,
        public int $width,
        public int $height,
    ) {
    }
}
