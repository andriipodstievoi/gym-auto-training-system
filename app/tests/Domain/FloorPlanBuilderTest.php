<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\FloorPlan\FloorPlan;
use App\Domain\FloorPlan\FloorPlanBuilder;
use App\Domain\FloorPlan\FloorPlanRoom;
use PHPUnit\Framework\TestCase;

/**
 * The plan is laid out rather than drawn, so a branch the back office invents
 * tomorrow still gets a usable floor plan without anyone opening a vector editor.
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

        self::assertCount(4, $plan->trainingRooms);
        self::assertSame(
            ['free-weights', 'machines', 'cardio', 'functional'],
            array_map(static fn (FloorPlanRoom $room): string => $room->svgId, $plan->trainingRooms),
        );
    }

    public function testABranchWithNoZonesStillGetsABuilding(): void
    {
        $plan = $this->builder->build([]);

        self::assertSame([], $plan->trainingRooms);
        self::assertNotEmpty($plan->serviceRooms);
    }

    public function testRoomsAreLookedUpBySvgId(): void
    {
        $plan = $this->builder->build(['cardio', 'studio']);

        self::assertSame('studio', $plan->roomFor('studio')?->svgId);
        self::assertNull($plan->roomFor('free-weights'));
    }

    public function testASingleZoneTakesTheWholeTrainingArea(): void
    {
        $room = $this->builder->build(['cardio'])->trainingRooms[0];

        self::assertSame(FloorPlanBuilder::AREA_LEFT, $room->x);
        self::assertSame(FloorPlanBuilder::AREA_TOP, $room->y);
        self::assertSame(FloorPlanBuilder::AREA_WIDTH, $room->width);
        self::assertSame(FloorPlanBuilder::AREA_HEIGHT, $room->height);
    }

    public function testAnOddCountEndsWithAFullWidthRoom(): void
    {
        $rooms = $this->builder->build(['free-weights', 'machines', 'cardio'])->trainingRooms;

        // Two side by side, then one spanning the floor.
        self::assertSame($rooms[0]->y, $rooms[1]->y);
        self::assertLessThan(FloorPlanBuilder::AREA_WIDTH, $rooms[0]->width);
        self::assertSame(FloorPlanBuilder::AREA_WIDTH, $rooms[2]->width);
        self::assertGreaterThan($rooms[0]->y, $rooms[2]->y);
    }

    public function testAnEvenCountFillsAGrid(): void
    {
        $rooms = $this->builder->build(['a', 'b', 'c', 'd'])->trainingRooms;

        self::assertSame($rooms[0]->y, $rooms[1]->y);
        self::assertSame($rooms[2]->y, $rooms[3]->y);
        self::assertSame($rooms[0]->x, $rooms[2]->x);
        self::assertSame($rooms[1]->x, $rooms[3]->x);
    }

    /**
     * The layout is only useful if it never spills out of the drawing or over
     * itself, whatever number of zones a branch happens to have.
     */
    public function testRoomsStayInsideTheBuildingAndNeverOverlap(): void
    {
        for ($count = 1; $count <= 8; ++$count) {
            $ids = array_map(static fn (int $i): string => 'zone-'.$i, range(1, $count));
            $rooms = $this->builder->build($ids)->trainingRooms;

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

    public function testServiceRoomsSitBesideTheTrainingFloor(): void
    {
        $plan = $this->builder->build(['cardio']);

        foreach ($plan->serviceRooms as $room) {
            self::assertLessThanOrEqual(FloorPlanBuilder::AREA_LEFT, $room->x + $room->width);
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
