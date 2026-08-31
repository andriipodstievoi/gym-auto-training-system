<?php

declare(strict_types=1);

namespace App\Domain\FloorPlan;

/**
 * One rectangle in the branch floor plan, in SVG user units.
 */
final readonly class FloorPlanRoom
{
    public function __construct(
        public string $svgId,
        public int $x,
        public int $y,
        public int $width,
        public int $height,
    ) {
    }

    public function centreX(): int
    {
        return $this->x + intdiv($this->width, 2);
    }

    public function centreY(): int
    {
        return $this->y + intdiv($this->height, 2);
    }
}
