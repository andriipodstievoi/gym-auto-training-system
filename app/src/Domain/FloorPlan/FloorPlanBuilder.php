<?php

declare(strict_types=1);

namespace App\Domain\FloorPlan;

/**
 * Lays one storey of a branch out as a plan instead of shipping a drawing.
 *
 * A hand-drawn SVG would only ever fit the branches that existed the day it
 * was drawn; the back office can add a fourth branch with six zones, or move
 * the spa downstairs. So the plan is generated: the storey's rooms are tiled
 * across the building, and each room carries its zone's
 * {@see \App\Entity\FloorZone::$svgId}, which is what the template hangs the
 * element id and the click handler on.
 *
 * Every room on the plan is a real zone - changing rooms and reception
 * included - so every one of them is clickable, translatable and editable in
 * the back office. Only the entrance stays a fixed marker, because a doorway
 * is not a room anyone can walk into and look at.
 */
final class FloorPlanBuilder
{
    public const int AREA_LEFT = 24;
    public const int AREA_TOP = 24;
    public const int AREA_WIDTH = 832;
    public const int AREA_HEIGHT = 456;

    private const int GAP = 14;

    /**
     * Two columns reads well up to four rooms; beyond that they get too tall
     * and thin, so a busier storey goes to three.
     */
    private const int WIDE_LAYOUT_THRESHOLD = 4;

    /**
     * @param list<string> $svgIds one per zone on this storey, in display order
     */
    public function build(array $svgIds): FloorPlan
    {
        $count = count($svgIds);

        if (0 === $count) {
            return new FloorPlan([]);
        }

        $columns = $count <= self::WIDE_LAYOUT_THRESHOLD ? 2 : 3;
        $rows = (int) ceil($count / $columns);

        $rooms = [];

        foreach ($svgIds as $index => $svgId) {
            $row = intdiv($index, $columns);

            // A row shares the width between however many rooms actually
            // landed on it, so a lone last room spans the floor rather than
            // leaving a hole where a neighbour would have been.
            $onThisRow = min($columns, $count - $row * $columns);

            [$y, $height] = $this->slice(self::AREA_TOP, self::AREA_HEIGHT, $row, $rows);
            [$x, $width] = $this->slice(self::AREA_LEFT, self::AREA_WIDTH, $index % $columns, $onThisRow);

            $rooms[] = new FloorPlanRoom($svgId, $x, $y, $width, $height);
        }

        return new FloorPlan($rooms);
    }

    /**
     * Cuts $total into $of equal tracks separated by {@see GAP}, and returns
     * the offset and size of track $index. Dividing the gapped span rather
     * than the bare one keeps the last track flush with the far edge.
     *
     * @return array{int, int}
     */
    private function slice(int $origin, int $total, int $index, int $of): array
    {
        $span = $total + self::GAP;

        $start = intdiv($span * $index, $of);
        $end = intdiv($span * ($index + 1), $of);

        return [$origin + $start, $end - $start - self::GAP];
    }
}
