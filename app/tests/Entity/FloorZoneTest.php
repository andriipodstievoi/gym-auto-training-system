<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Domain\Enum\EquipmentType;
use App\Domain\Enum\ZoneKind;
use App\Domain\TranslatedString;
use App\Entity\Branch;
use App\Entity\Equipment;
use App\Entity\FloorZone;
use PHPUnit\Framework\TestCase;

/**
 * A zone on the floor plan: which storey it sits on, what kind of room it is,
 * and how much is standing in it.
 *
 * getUnitCount() is the one number here nobody stores, and it is the one the
 * plan prints, so it gets most of the attention.
 */
final class FloorZoneTest extends TestCase
{
    public function testANewZoneIsAnEmptyTrainingFloorOnTheGroundStorey(): void
    {
        $zone = new FloorZone();

        self::assertSame(ZoneKind::TRAINING, $zone->getKind());
        self::assertSame(0, $zone->getFloor());
        self::assertSame(0, $zone->getPosition());
        self::assertCount(0, $zone->getEquipment());
        self::assertSame(0, $zone->getUnitCount());
        self::assertTrue($zone->getName()->isEmpty());
    }

    /**
     * Six racks are six things on the plan, not one line item.
     */
    public function testTheUnitCountAddsUpQuantitiesRatherThanLines(): void
    {
        $zone = new FloorZone();
        self::equipment($zone, 'Squat rack', EquipmentType::BARBELL, 6);
        self::equipment($zone, 'Flat bench', EquipmentType::BARBELL, 4);

        self::assertCount(2, $zone->getEquipment());
        self::assertSame(10, $zone->getUnitCount());
    }

    public function testRemovingALineTakesItsUnitsWithIt(): void
    {
        $zone = new FloorZone();
        $racks = self::equipment($zone, 'Squat rack', EquipmentType::BARBELL, 6);
        self::equipment($zone, 'Flat bench', EquipmentType::BARBELL, 4);

        $zone->removeEquipment($racks);

        self::assertSame(4, $zone->getUnitCount());
        self::assertNull($racks->getZone());
    }

    /**
     * An amenity room holds fittings members merely use rather than machines
     * they train on, but the plan still has to know how many are in there.
     */
    public function testAnAmenityRoomCountsItsFixturesLikeAnyOtherZone(): void
    {
        $zone = (new FloorZone())->setKind(ZoneKind::AMENITY)->setFloor(1);
        self::equipment($zone, 'Locker', EquipmentType::FIXTURE, 120);
        self::equipment($zone, 'Shower', EquipmentType::FIXTURE, 8);

        self::assertSame(ZoneKind::AMENITY, $zone->getKind());
        self::assertSame(128, $zone->getUnitCount());

        // Upstairs, which is why the plan is drawn one storey at a time.
        self::assertSame(1, $zone->getFloor());
    }

    public function testAddingEquipmentSetsBothSidesAndDoesNotDuplicate(): void
    {
        $zone = new FloorZone();
        $item = new Equipment();

        $zone->addEquipment($item)->addEquipment($item);

        self::assertCount(1, $zone->getEquipment());
        self::assertSame($zone, $item->getZone());
    }

    /**
     * Orphan removal is on this collection, so detaching another zone's
     * equipment would delete a row that was never ours.
     */
    public function testRemovingEquipmentThatBelongsElsewhereLeavesItsZoneAlone(): void
    {
        $mine = new FloorZone();
        $theirs = new FloorZone();
        $item = new Equipment();

        $theirs->addEquipment($item);
        $mine->removeEquipment($item);

        self::assertSame($theirs, $item->getZone());
        self::assertSame(1, $theirs->getUnitCount());
    }

    /**
     * The svg id is what ties the row to a shape in the floor-plan drawing, so
     * it is stored verbatim rather than derived from the name.
     */
    public function testTheSvgIdIsItsOwnHandleOnTheDrawing(): void
    {
        $zone = (new FloorZone())->setSvgId('free-weights');
        $zone->setName(TranslatedString::of('Free weights', 'Brīvie svari'));

        self::assertSame('free-weights', $zone->getSvgId());

        // Renaming the room does not move the shape it points at.
        $zone->setName(TranslatedString::of('Strength hall'));
        self::assertSame('free-weights', $zone->getSvgId());
    }

    public function testAZonePrintsAsItsNameInTheDefaultLocale(): void
    {
        $zone = new FloorZone();
        $zone->setName(new TranslatedString(['lv' => 'Brīvie svari']));

        // No English, so __toString falls back the documented way.
        self::assertSame('Brīvie svari', (string) $zone);
    }

    public function testTheSettersChainAndStoreWhatTheyWereGiven(): void
    {
        $branch = new Branch();
        $zone = new FloorZone();
        $description = TranslatedString::of('Racks and platforms');

        $returned = $zone
            ->setBranch($branch)
            ->setSvgId('free-weights')
            ->setDescription($description)
            ->setPosition(3)
            ->setFloor(2)
            ->setKind(ZoneKind::AMENITY);

        self::assertSame($zone, $returned);
        self::assertSame($branch, $zone->getBranch());
        self::assertSame('free-weights', $zone->getSvgId());
        self::assertSame($description, $zone->getDescription());
        self::assertSame(3, $zone->getPosition());
        self::assertSame(2, $zone->getFloor());
        self::assertSame(ZoneKind::AMENITY, $zone->getKind());
        self::assertNull($zone->getId());
    }

    private static function equipment(FloorZone $zone, string $name, EquipmentType $type, int $quantity): Equipment
    {
        $item = (new Equipment())->setType($type)->setQuantity($quantity);
        $item->setName(TranslatedString::of($name));

        $zone->addEquipment($item);

        return $item;
    }
}
