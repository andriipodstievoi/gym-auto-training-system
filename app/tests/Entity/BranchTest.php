<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Domain\OpeningSchedule;
use App\Domain\TranslatedString;
use App\Entity\Branch;
use App\Entity\FloorZone;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * A location, its week, and the zones hanging off it.
 *
 * The opening hours are a raw JSON array on the entity because that is the
 * shape the back office edits; every question the site asks of them goes
 * through {@see OpeningSchedule}. What is worth pinning here is that the two
 * still agree on the shape, and that the floor-zone collection keeps both
 * sides of the relation honest.
 */
final class BranchTest extends TestCase
{
    public function testANewBranchIsOpenForBusinessInRigaWithNothingInIt(): void
    {
        $branch = new Branch();

        self::assertTrue($branch->isActive());
        self::assertSame('Rīga', $branch->getCity());
        self::assertCount(0, $branch->getFloorZones());
        self::assertSame([], $branch->getOpeningHours());

        // Not null: the templates call ->get() on it before anybody has typed
        // a description.
        self::assertInstanceOf(TranslatedString::class, $branch->getDescription());
        self::assertTrue($branch->getDescription()->isEmpty());
    }

    public function testTheStoredHoursAreTheShapeTheScheduleReads(): void
    {
        $branch = (new Branch())->setOpeningHours([
            1 => ['open' => '06:00', 'close' => '23:00'],
            6 => ['open' => '09:00', 'close' => '21:00'],
        ]);

        $schedule = OpeningSchedule::fromArray($branch->getOpeningHours());

        self::assertFalse($schedule->isEmpty());
        self::assertSame('06:00', $schedule->forDay(1)?->open);
        self::assertNull($schedule->forDay(2));

        // A Monday morning inside the window, and a Tuesday that has no window.
        self::assertTrue($schedule->isOpenAt(new DateTimeImmutable('2026-09-07 07:30')));
        self::assertFalse($schedule->isOpenAt(new DateTimeImmutable('2026-09-08 07:30')));
    }

    public function testABranchThatHasNotFilledInItsHoursSaysSoRatherThanLookingClosedAllWeek(): void
    {
        $schedule = OpeningSchedule::fromArray((new Branch())->getOpeningHours());

        self::assertTrue($schedule->isEmpty());
        self::assertSame([], $schedule->grouped());
    }

    public function testAddingAZoneSetsBothSidesOfTheRelation(): void
    {
        $branch = new Branch();
        $zone = new FloorZone();

        $branch->addFloorZone($zone);

        self::assertTrue($branch->getFloorZones()->contains($zone));
        self::assertSame($branch, $zone->getBranch());
    }

    public function testAZoneIsNotAddedTwice(): void
    {
        $branch = new Branch();
        $zone = new FloorZone();

        $branch->addFloorZone($zone)->addFloorZone($zone);

        self::assertCount(1, $branch->getFloorZones());
    }

    public function testRemovingAZoneClearsItsBackReference(): void
    {
        $branch = new Branch();
        $zone = new FloorZone();

        $branch->addFloorZone($zone);
        $branch->removeFloorZone($zone);

        self::assertCount(0, $branch->getFloorZones());
        self::assertNull($zone->getBranch());
    }

    /**
     * Orphan removal is on this collection, so detaching the wrong branch's
     * zone would delete a row that was never ours.
     */
    public function testRemovingAZoneThatBelongsElsewhereLeavesItsOwnerAlone(): void
    {
        $mine = new Branch();
        $theirs = new Branch();
        $zone = new FloorZone();

        $theirs->addFloorZone($zone);
        $mine->removeFloorZone($zone);

        self::assertSame($theirs, $zone->getBranch());
        self::assertTrue($theirs->getFloorZones()->contains($zone));
    }

    public function testABranchPrintsAsItsName(): void
    {
        self::assertSame('SPĒKS Centrs', (string) (new Branch())->setName('SPĒKS Centrs'));
    }

    /**
     * The back office edits a branch through fluent chains, so every setter
     * has to hand the same instance back rather than a copy.
     */
    public function testTheSettersChainAndStoreWhatTheyWereGiven(): void
    {
        $branch = new Branch();
        $description = TranslatedString::of('The flagship');

        $returned = $branch
            ->setSlug('centrs')
            ->setName('SPĒKS Centrs')
            ->setDescription($description)
            ->setAddressLine('Brīvības iela 100')
            ->setCity('Rīga')
            ->setPostalCode('LV-1001')
            ->setLatitude(56.9496)
            ->setLongitude(24.1052)
            ->setPhone('+371 20000000')
            ->setEmail('centrs@speks.lv')
            ->setActive(false);

        self::assertSame($branch, $returned);
        self::assertSame('centrs', $branch->getSlug());
        self::assertSame('SPĒKS Centrs', $branch->getName());
        self::assertSame($description, $branch->getDescription());
        self::assertSame('Brīvības iela 100', $branch->getAddressLine());
        self::assertSame('LV-1001', $branch->getPostalCode());
        self::assertSame(56.9496, $branch->getLatitude());
        self::assertSame(24.1052, $branch->getLongitude());
        self::assertSame('+371 20000000', $branch->getPhone());
        self::assertSame('centrs@speks.lv', $branch->getEmail());
        self::assertFalse($branch->isActive());
        self::assertNull($branch->getId());
    }
}
