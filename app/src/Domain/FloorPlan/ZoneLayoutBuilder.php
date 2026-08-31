<?php

declare(strict_types=1);

namespace App\Domain\FloorPlan;

use App\Domain\Enum\EquipmentType;

/**
 * Places every individual machine inside one zone.
 *
 * Same reasoning as {@see FloorPlanBuilder}: coordinates typed into the
 * database would only describe the branches that existed the day somebody
 * typed them, and there is no editor to move them afterwards. So the plan is
 * generated - each inventory line becomes a labelled bay, and each unit in
 * that line becomes its own footprint, sized by what the thing actually is.
 * Ten treadmills draw ten treadmills.
 *
 * The result is a plausible arrangement rather than a survey of the real
 * room, which is the honest trade for it working on any branch the back
 * office invents.
 */
final class ZoneLayoutBuilder
{
    public const int AREA_LEFT = 32;
    public const int AREA_TOP = 32;
    public const int AREA_WIDTH = 816;
    public const int AREA_HEIGHT = 456;

    /**
     * Footprints in grid cells, as [columns, rows]. A power rack needs a
     * platform around it; a kettlebell needs a square of floor.
     *
     * @var array<string, array{int, int}>
     */
    private const array FOOTPRINTS = [
        EquipmentType::BARBELL->value => [3, 2],
        EquipmentType::BODYWEIGHT->value => [3, 2],
        EquipmentType::DUMBBELL->value => [3, 1],
        EquipmentType::MACHINE->value => [2, 2],
        EquipmentType::CABLE->value => [2, 2],
        EquipmentType::CARDIO->value => [2, 2],
        EquipmentType::KETTLEBELL->value => [1, 1],
        EquipmentType::BAND->value => [1, 1],
        EquipmentType::FIXTURE->value => [2, 1],
    ];

    /**
     * Tried largest first; the first size whose bays fit the room wins. A
     * changing room with forty lockers simply ends up drawn smaller than a
     * platform area with four racks.
     *
     * The largest is deliberately modest: sizing purely to fill the room makes
     * a quiet zone draw half a dozen enormous slabs, which reads as abstract
     * art rather than a floor. Machines should look like machines, with floor
     * left around them.
     *
     * @var list<int>
     */
    private const array CELL_SIZES = [40, 34, 28, 24, 20, 16, 12, 9];

    /**
     * @param list<ZoneItem> $items
     */
    public function build(array $items): ZoneLayout
    {
        $items = array_values(array_filter($items, static fn (ZoneItem $i): bool => $i->quantity > 0));

        if ([] === $items) {
            return new ZoneLayout([]);
        }

        foreach (self::CELL_SIZES as $cell) {
            $groups = $this->layOut($items, $cell);

            if (null !== $groups) {
                return new ZoneLayout($groups);
            }
        }

        // Nothing fit even at the smallest cell; draw at that size and accept
        // a dense room rather than an empty one.
        return new ZoneLayout($this->layOut($items, self::CELL_SIZES[array_key_last(self::CELL_SIZES)], true) ?? []);
    }

    /**
     * @param list<ZoneItem> $items
     *
     * @return list<MachineGroup>|null null when the bays overflow the room
     */
    private function layOut(array $items, int $cell, bool $force = false): ?array
    {
        $gap = max(2, intdiv($cell, 8));
        $labelHeight = max(11, intdiv($cell * 3, 4));
        $bottom = self::AREA_TOP + self::AREA_HEIGHT;

        $groups = [];
        $y = self::AREA_TOP;

        foreach ($items as $item) {
            // Every EquipmentType has a footprint; a new case without one is a
            // static-analysis failure rather than a silent default.
            [$columns, $rows] = self::FOOTPRINTS[$item->type->value];
            $width = $columns * $cell;
            $height = $rows * $cell;

            // Each line opens its own bay, under its own label.
            $labelY = $y + intdiv($labelHeight, 2);
            $y += $labelHeight;

            $x = self::AREA_LEFT;
            $rowHeight = 0;
            $units = [];

            for ($unit = 0; $unit < $item->quantity; ++$unit) {
                if ($x > self::AREA_LEFT && $x + $width > self::AREA_LEFT + self::AREA_WIDTH) {
                    $x = self::AREA_LEFT;
                    $y += $rowHeight + $gap;
                    $rowHeight = 0;
                }

                $units[] = new MachinePlacement($x, $y, $width, $height);

                $x += $width + $gap;
                $rowHeight = max($rowHeight, $height);
            }

            $groups[] = new MachineGroup(
                $item->key,
                $item->label,
                $item->type,
                self::AREA_LEFT,
                $labelY,
                $units,
            );

            $y += $rowHeight + $gap * 2;

            if (!$force && $y > $bottom) {
                return null;
            }
        }

        return $groups;
    }
}
