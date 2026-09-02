<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Domain\Enum\EquipmentType;
use App\Domain\TranslatedString;
use App\Entity\Equipment;
use App\Entity\FloorZone;
use PHPUnit\Framework\TestCase;

/**
 * A line of kit standing in a zone.
 *
 * The interesting part is {@see EquipmentType::FIXTURE}: it is the one case in
 * the enum that is not exercise equipment. Lockers and showers are things an
 * amenity room contains, not things a programme can be built out of, so
 * nothing that offers equipment to a member may ever offer it.
 */
final class EquipmentTest extends TestCase
{
    public function testANewPieceIsOneMachineWithNoNameYet(): void
    {
        $item = new Equipment();

        self::assertSame(EquipmentType::MACHINE, $item->getType());
        self::assertSame(1, $item->getQuantity());
        self::assertTrue($item->getName()->isEmpty());
        self::assertNull($item->getZone());
        self::assertNull($item->getId());
    }

    /**
     * A fitting is never offered as something to train with, which is what
     * keeps "Lockers" out of a generated programme.
     */
    public function testAFixtureIsNotSomethingAMemberCanTrainWith(): void
    {
        $locker = (new Equipment())->setType(EquipmentType::FIXTURE);

        self::assertSame(EquipmentType::FIXTURE, $locker->getType());
        self::assertNotContains(EquipmentType::FIXTURE, EquipmentType::homeBasic());
    }

    public function testAHomeSetupIsOnlyTheKitSomebodyPlausiblyOwns(): void
    {
        $home = EquipmentType::homeBasic();

        self::assertSame(
            [EquipmentType::DUMBBELL, EquipmentType::KETTLEBELL, EquipmentType::BAND, EquipmentType::BODYWEIGHT],
            $home,
        );

        foreach ([EquipmentType::BARBELL, EquipmentType::MACHINE, EquipmentType::CABLE, EquipmentType::CARDIO] as $gymOnly) {
            self::assertNotContains($gymOnly, $home, $gymOnly->value.' is gym kit, not home kit.');
        }
    }

    public function testMovingAPieceBetweenZonesLeavesItInExactlyOne(): void
    {
        $downstairs = new FloorZone();
        $upstairs = new FloorZone();
        $item = (new Equipment())->setQuantity(2);

        $downstairs->addEquipment($item);
        $downstairs->removeEquipment($item);
        $upstairs->addEquipment($item);

        self::assertSame($upstairs, $item->getZone());
        self::assertSame(0, $downstairs->getUnitCount());
        self::assertSame(2, $upstairs->getUnitCount());
    }

    public function testAPiecePrintsAsItsNameInTheDefaultLocale(): void
    {
        $item = new Equipment();
        $item->setName(new TranslatedString(['ru' => 'Скамья']));

        // English and Latvian are both missing, so the Russian text is all
        // there is to print.
        self::assertSame('Скамья', (string) $item);
    }

    public function testTheSettersChainAndStoreWhatTheyWereGiven(): void
    {
        $zone = new FloorZone();
        $item = new Equipment();
        $name = TranslatedString::of('Cable crossover');

        $returned = $item->setZone($zone)->setType(EquipmentType::CABLE)->setQuantity(3)->setName($name);

        self::assertSame($item, $returned);
        self::assertSame($zone, $item->getZone());
        self::assertSame(EquipmentType::CABLE, $item->getType());
        self::assertSame(3, $item->getQuantity());
        self::assertSame($name, $item->getName());
    }
}
