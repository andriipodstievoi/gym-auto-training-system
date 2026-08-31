<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\Enum\EquipmentType;
use App\Domain\FloorPlan\MachineGroup;
use App\Domain\FloorPlan\MachinePlacement;
use App\Domain\FloorPlan\ZoneItem;
use App\Domain\FloorPlan\ZoneLayout;
use App\Domain\FloorPlan\ZoneLayoutBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The detailed view of one zone: every machine gets its own footprint, so a
 * cardio room with ten treadmills draws ten treadmills.
 */
final class ZoneLayoutBuilderTest extends TestCase
{
    private ZoneLayoutBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ZoneLayoutBuilder();
    }

    public function testAnEmptyZoneLaysOutNothing(): void
    {
        self::assertSame([], $this->builder->build([])->groups);
    }

    public function testEveryUnitOfEveryLineIsDrawn(): void
    {
        $layout = $this->builder->build([
            new ZoneItem('treadmill', 'Treadmill', EquipmentType::CARDIO, 10),
            new ZoneItem('rower', 'Rowing machine', EquipmentType::CARDIO, 4),
        ]);

        self::assertCount(2, $layout->groups);
        self::assertCount(10, $layout->groups[0]->units);
        self::assertCount(4, $layout->groups[1]->units);
        self::assertSame(14, $this->unitCount($layout));
    }

    public function testGroupsKeepTheirNameAndType(): void
    {
        $layout = $this->builder->build([
            new ZoneItem('rack', 'Power rack', EquipmentType::BARBELL, 2),
        ]);

        self::assertSame('rack', $layout->groups[0]->key);
        self::assertSame('Power rack', $layout->groups[0]->label);
        self::assertSame(EquipmentType::BARBELL, $layout->groups[0]->type);
    }

    /**
     * A rack takes more floor than a kettlebell, and the plan should say so.
     */
    public function testFootprintsVaryByEquipmentType(): void
    {
        $rack = $this->builder->build([new ZoneItem('r', 'Rack', EquipmentType::BARBELL, 1)])
            ->groups[0]->units[0];
        $bell = $this->builder->build([new ZoneItem('k', 'Kettlebells', EquipmentType::KETTLEBELL, 1)])
            ->groups[0]->units[0];

        self::assertGreaterThan($bell->width, $rack->width);
        self::assertGreaterThan($bell->height, $rack->height);
    }

    public function testEveryMachineStaysInsideTheRoom(): void
    {
        $layout = $this->builder->build([
            new ZoneItem('rack', 'Power rack', EquipmentType::BARBELL, 6),
            new ZoneItem('bench', 'Bench', EquipmentType::BARBELL, 4),
            new ZoneItem('db', 'Dumbbell rack', EquipmentType::DUMBBELL, 2),
        ]);

        foreach ($this->allUnits($layout) as $unit) {
            self::assertGreaterThanOrEqual(ZoneLayoutBuilder::AREA_LEFT, $unit->x);
            self::assertGreaterThanOrEqual(ZoneLayoutBuilder::AREA_TOP, $unit->y);
            self::assertLessThanOrEqual(
                ZoneLayoutBuilder::AREA_LEFT + ZoneLayoutBuilder::AREA_WIDTH,
                $unit->x + $unit->width,
            );
            self::assertLessThanOrEqual(
                ZoneLayoutBuilder::AREA_TOP + ZoneLayoutBuilder::AREA_HEIGHT,
                $unit->y + $unit->height,
            );
        }
    }

    public function testNoTwoMachinesStandInTheSamePlace(): void
    {
        $layout = $this->builder->build([
            new ZoneItem('tread', 'Treadmill', EquipmentType::CARDIO, 10),
            new ZoneItem('bike', 'Air bike', EquipmentType::CARDIO, 4),
            new ZoneItem('rig', 'Pull-up rig', EquipmentType::BODYWEIGHT, 2),
        ]);

        $units = $this->allUnits($layout);

        foreach ($units as $i => $unit) {
            foreach (array_slice($units, $i + 1) as $other) {
                self::assertFalse(
                    $unit->x < $other->x + $other->width
                    && $other->x < $unit->x + $unit->width
                    && $unit->y < $other->y + $other->height
                    && $other->y < $unit->y + $unit->height,
                    'two machines overlap',
                );
            }
        }
    }

    /**
     * A changing room holds far more lockers than a gym floor holds racks; the
     * layout has to shrink rather than run off the bottom of the drawing.
     */
    public function testACrowdedRoomShrinksToFit(): void
    {
        $layout = $this->builder->build([
            new ZoneItem('lockers', 'Lockers', EquipmentType::FIXTURE, 24),
            new ZoneItem('showers', 'Showers', EquipmentType::FIXTURE, 8),
            new ZoneItem('benches', 'Benches', EquipmentType::FIXTURE, 6),
        ]);

        self::assertSame(38, $this->unitCount($layout));

        foreach ($this->allUnits($layout) as $unit) {
            self::assertLessThanOrEqual(
                ZoneLayoutBuilder::AREA_TOP + ZoneLayoutBuilder::AREA_HEIGHT,
                $unit->y + $unit->height,
            );
            self::assertGreaterThan(0, $unit->width);
            self::assertGreaterThan(0, $unit->height);
        }
    }

    public function testEachGroupStartsOnItsOwnRowUnderItsLabel(): void
    {
        $layout = $this->builder->build([
            new ZoneItem('a', 'First', EquipmentType::MACHINE, 3),
            new ZoneItem('b', 'Second', EquipmentType::MACHINE, 3),
        ]);

        [$first, $second] = $layout->groups;

        // The label sits above its own machines...
        self::assertLessThan($first->units[0]->y, $first->labelY);
        // ...and the next bay starts below the previous one.
        self::assertGreaterThan($first->units[0]->y, $second->units[0]->y);
    }

    public function testTheViewBoxMatchesTheOverviewPlan(): void
    {
        self::assertSame('0 0 880 520', ZoneLayout::VIEW_BOX);
    }

    /**
     * @return list<MachinePlacement>
     */
    private function allUnits(ZoneLayout $layout): array
    {
        return array_merge(...array_map(
            static fn (MachineGroup $group): array => $group->units,
            $layout->groups,
        ));
    }

    private function unitCount(ZoneLayout $layout): int
    {
        return count($this->allUnits($layout));
    }
}
