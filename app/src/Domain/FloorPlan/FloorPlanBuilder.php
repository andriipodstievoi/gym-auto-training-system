<?php

declare(strict_types=1);

namespace App\Domain\FloorPlan;

/**
 * Lays a branch's floor zones out as a plan instead of shipping a drawing.
 *
 * A hand-drawn SVG would only ever fit the branches that existed the day it
 * was drawn; the back office can add a fourth branch with six zones. So the
 * plan is generated: the entrance side of the building is fixed, and the
 * training floor is divided between however many zones the branch has, in two
 * columns, with an odd last zone spanning the width.
 *
 * Each room carries its zone's {@see \App\Entity\FloorZone::$svgId}, which is
 * what the template hangs the id and the click handler on.
 */
final class FloorPlanBuilder
{
    public const int AREA_LEFT = 220;
    public const int AREA_TOP = 20;
    public const int AREA_WIDTH = 640;
    public const int AREA_HEIGHT = 480;

    private const int GAP = 16;
    private const int COLUMNS = 2;

    /**
     * The reception side, which every branch has and none of which is clickable.
     *
     * @var list<array{string, int, int, int, int}>
     */
    private const array SERVICE_ROOMS = [
        ['changing-rooms', 20, 20, 180, 234],
        ['reception', 20, 270, 180, 114],
        ['entrance', 20, 400, 180, 100],
    ];

    /**
     * @param list<string> $svgIds one per floor zone, in display order
     */
    public function build(array $svgIds): FloorPlan
    {
        return new FloorPlan($this->layOutTrainingFloor($svgIds), $this->serviceRooms());
    }

    /**
     * @param list<string> $svgIds
     *
     * @return list<FloorPlanRoom>
     */
    private function layOutTrainingFloor(array $svgIds): array
    {
        $count = count($svgIds);

        if (0 === $count) {
            return [];
        }

        $rows = (int) ceil($count / self::COLUMNS);
        $rooms = [];

        foreach ($svgIds as $index => $svgId) {
            $row = intdiv($index, self::COLUMNS);
            [$y, $height] = $this->slice(self::AREA_TOP, self::AREA_HEIGHT, $row, $rows);

            // A lone zone on the final row gets the whole width rather than
            // leaving a gap where a second room would have been.
            $isLastAndAlone = $index === $count - 1 && 0 !== $count % self::COLUMNS;

            if ($isLastAndAlone) {
                $rooms[] = new FloorPlanRoom($svgId, self::AREA_LEFT, $y, self::AREA_WIDTH, $height);

                continue;
            }

            [$x, $width] = $this->slice(
                self::AREA_LEFT,
                self::AREA_WIDTH,
                $index % self::COLUMNS,
                self::COLUMNS,
            );

            $rooms[] = new FloorPlanRoom($svgId, $x, $y, $width, $height);
        }

        return $rooms;
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

    /**
     * @return list<FloorPlanRoom>
     */
    private function serviceRooms(): array
    {
        return array_map(
            static fn (array $room): FloorPlanRoom => new FloorPlanRoom(...$room),
            self::SERVICE_ROOMS,
        );
    }
}
