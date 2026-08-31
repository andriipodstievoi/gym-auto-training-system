<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\FloorPlan\FloorPlan;
use App\Domain\FloorPlan\FloorPlanBuilder;
use App\Domain\FloorPlan\FloorPlanRoom;
use PHPUnit\Framework\TestCase;

/**
 * The plan is laid out rather than drawn, so a branch the back office invents
 * tomorrow still gets a usable floor plan without anyone opening a vector
 * editor. One storey at a time: the lounge and spa are upstairs.
 */
final class FloorPlanBuilderTest extends TestCase
{
    private FloorPlanBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new FloorPlanBuilder();
    }

    public function testEveryZoneBecomesExactlyOneRoom(): void
    {
        $plan = $this->builder->build(['free-weights', 'machines', 'cardio', 'functional']);

        self::assertCount(4, $plan->rooms);
        self::assertSame(
            ['free-weights', 'machines', 'cardio', 'functional'],
            array_map(static fn (FloorPlanRoom $room): string => $room->svgId, $plan->rooms),
        );
    }

    public function testAStoreyWithNoZonesIsEmpty(): void
    {
        $plan = $this->builder->build([]);

        self::assertTrue($plan->isEmpty());
        self::assertSame([], $plan->rooms);
    }

    public function testRoomsAreLookedUpBySvgId(): void
    {
        $plan = $this->builder->build(['lounge', 'spa']);

        self::assertSame('spa', $plan->roomFor('spa')?->svgId);
        self::assertNull($plan->roomFor('free-weights'));
    }

    public function testASingleZoneTakesTheWholeStorey(): void
    {
        $room = $this->builder->build(['cardio'])->rooms[0];

        self::assertSame(FloorPlanBuilder::AREA_LEFT, $room->x);
        self::assertSame(FloorPlanBuilder::AREA_TOP, $room->y);
        self::assertSame(FloorPlanBuilder::AREA_WIDTH, $room->width);
        self::assertSame(FloorPlanBuilder::AREA_HEIGHT, $room->height);
    }

    /**
     * The upstairs case: a lounge and a spa side by side, filling the storey.
     */
    public function testTwoZonesSitSideBySide(): void
    {
        $rooms = $this->builder->build(['lounge', 'spa'])->rooms;

        self::assertSame($rooms[0]->y, $rooms[1]->y);
        self::assertSame(FloorPlanBuilder::AREA_HEIGHT, $rooms[0]->height);
        self::assertGreaterThan($rooms[0]->x, $rooms[1]->x);
    }

    public function testALoneRoomOnTheLastRowSpansTheFloor(): void
    {
        $rooms = $this->builder->build(['free-weights', 'machines', 'cardio'])->rooms;

        self::assertSame($rooms[0]->y, $rooms[1]->y);
        self::assertLessThan(FloorPlanBuilder::AREA_WIDTH, $rooms[0]->width);
        self::assertSame(FloorPlanBuilder::AREA_WIDTH, $rooms[2]->width);
        self::assertGreaterThan($rooms[0]->y, $rooms[2]->y);
    }

    /**
     * A full ground floor - four training zones plus two changing rooms and a
     * reception - moves to three columns so the rooms stay legible.
     */
    public function testABusyStoreyUsesThreeColumns(): void
    {
        $rooms = $this->builder->build([
            'free-weights', 'machines', 'cardio', 'functional',
            'changing-men', 'changing-women', 'reception',
        ])->rooms;

        self::assertSame($rooms[0]->y, $rooms[1]->y);
        self::assertSame($rooms[1]->y, $rooms[2]->y);
        self::assertGreaterThan($rooms[0]->y, $rooms[3]->y);

        // Seven rooms over three columns leaves one alone on the last row.
        self::assertSame(FloorPlanBuilder::AREA_WIDTH, $rooms[6]->width);
    }

    /**
     * The layout is only useful if it never spills out of the building or over
     * itself, whatever number of zones a storey happens to have.
     */
    public function testRoomsStayInsideTheBuildingAndNeverOverlap(): void
    {
        for ($count = 1; $count <= 12; ++$count) {
            $ids = array_map(static fn (int $i): string => 'zone-'.$i, range(1, $count));
            $rooms = $this->builder->build($ids)->rooms;

            foreach ($rooms as $room) {
                self::assertGreaterThanOrEqual(FloorPlanBuilder::AREA_LEFT, $room->x, "count $count");
                self::assertGreaterThanOrEqual(FloorPlanBuilder::AREA_TOP, $room->y, "count $count");
                self::assertLessThanOrEqual(
                    FloorPlanBuilder::AREA_LEFT + FloorPlanBuilder::AREA_WIDTH,
                    $room->x + $room->width,
                    "count $count",
                );
                self::assertLessThanOrEqual(
                    FloorPlanBuilder::AREA_TOP + FloorPlanBuilder::AREA_HEIGHT,
                    $room->y + $room->height,
                    "count $count",
                );
                self::assertGreaterThan(0, $room->width, "count $count");
                self::assertGreaterThan(0, $room->height, "count $count");
            }

            foreach ($rooms as $i => $room) {
                foreach (array_slice($rooms, $i + 1) as $other) {
                    self::assertFalse($this->overlaps($room, $other), "count $count: rooms overlap");
                }
            }
        }
    }

    public function testTheViewBoxContainsTheWholeBuilding(): void
    {
        self::assertSame('0 0 880 520', FloorPlan::VIEW_BOX);
    }

    private function overlaps(FloorPlanRoom $a, FloorPlanRoom $b): bool
    {
        return $a->x < $b->x + $b->width
            && $b->x < $a->x + $a->width
            && $a->y < $b->y + $b->height
            && $b->y < $a->y + $a->height;
    }
}
