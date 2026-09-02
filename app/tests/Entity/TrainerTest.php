<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Domain\Enum\TrainerSpeciality;
use App\Domain\TranslatedString;
use App\Entity\Branch;
use App\Entity\Trainer;
use App\Entity\TrainerAvailability;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * A coach as the public site sees them.
 *
 * Two JSON lists carry most of the profile - specialities and languages - and
 * both are free-form columns rather than enum columns, so the reads have to
 * cope with whatever an older release wrote into them. The account is
 * deliberately optional: a coach is on the site the day staff add them, and
 * only gets a login once there is a schedule to sign in for.
 */
final class TrainerTest extends TestCase
{
    public function testANewCoachIsOnTheSiteWithNothingFilledIn(): void
    {
        $trainer = new Trainer();

        self::assertTrue($trainer->isActive());
        self::assertSame(0, $trainer->getHourlyRateCents());
        self::assertSame([], $trainer->getSpecialities());
        self::assertSame([], $trainer->getLanguages());
        self::assertSame([], $trainer->getSpecialityEnums());
        self::assertCount(0, $trainer->getAvailability());
        self::assertCount(0, $trainer->getBookings());
        self::assertNull($trainer->getBranch());
        self::assertNull($trainer->getPhotoPath());

        self::assertInstanceOf(TranslatedString::class, $trainer->getBio());
        self::assertTrue($trainer->getBio()->isEmpty());
    }

    /**
     * A coach goes on the public site before they ever need a password, so an
     * absent account is the normal state rather than a broken one.
     */
    public function testACoachNeedsNoAccountToBeListed(): void
    {
        $trainer = (new Trainer())->setFullName('Ilze Ozola')->setSlug('ilze-ozola');

        self::assertNull($trainer->getUser());
        self::assertTrue($trainer->isActive());
    }

    /**
     * Deleting the login leaves the profile standing - the column is SET NULL,
     * and the entity has to accept that state rather than assume a user.
     */
    public function testTakingTheAccountAwayLeavesTheProfileStanding(): void
    {
        $user = (new User())->setEmail('ilze@speks.lv');
        $trainer = (new Trainer())->setFullName('Ilze Ozola')->setUser($user);

        self::assertSame($user, $trainer->getUser());

        $trainer->setUser(null);

        self::assertNull($trainer->getUser());
        self::assertSame('Ilze Ozola', $trainer->getFullName());
    }

    public function testSpecialitiesAreReadBackAsEnumCases(): void
    {
        $trainer = (new Trainer())->setSpecialities(['strength', 'rehab']);

        self::assertSame(
            [TrainerSpeciality::STRENGTH, TrainerSpeciality::REHAB],
            $trainer->getSpecialityEnums(),
        );
    }

    /**
     * The column is plain JSON, so it can hold a speciality that no longer
     * exists. That must thin the list out, not blow the profile page up.
     */
    public function testASpecialityTheEnumNoLongerKnowsIsDroppedAndTheListStaysAList(): void
    {
        $trainer = (new Trainer())->setSpecialities(['strength', 'aerobics-1998', 'rehab']);

        $enums = $trainer->getSpecialityEnums();

        self::assertSame([TrainerSpeciality::STRENGTH, TrainerSpeciality::REHAB], $enums);
        self::assertSame([0, 1], array_keys($enums));
    }

    public function testEverySpecialityTheEnumOffersSurvivesTheRoundTrip(): void
    {
        $values = array_map(static fn (TrainerSpeciality $case): string => $case->value, TrainerSpeciality::cases());

        self::assertSame(TrainerSpeciality::cases(), (new Trainer())->setSpecialities($values)->getSpecialityEnums());
    }

    public function testACoachSpeaksOnlyTheLanguagesListed(): void
    {
        $trainer = (new Trainer())->setLanguages(['lv', 'ru']);

        self::assertTrue($trainer->speaks('lv'));
        self::assertTrue($trainer->speaks('ru'));
        self::assertFalse($trainer->speaks('en'));

        // Locale codes are matched exactly - no case folding, no prefixes.
        self::assertFalse($trainer->speaks('LV'));
        self::assertFalse($trainer->speaks('lv-LV'));
    }

    public function testACoachWithNoHoursSetCannotBeBookedAtAll(): void
    {
        self::assertFalse((new Trainer())->hasActiveAvailability());
    }

    public function testACoachWhoHasPausedEveryWindowCannotBeBookedEither(): void
    {
        $trainer = new Trainer();
        self::window($trainer, 2)->setActive(false);
        self::window($trainer, 4)->setActive(false);

        self::assertCount(2, $trainer->getAvailability());
        self::assertFalse($trainer->hasActiveAvailability());
    }

    public function testOneLiveWindowIsEnoughToOfferSlots(): void
    {
        $trainer = new Trainer();
        self::window($trainer, 2)->setActive(false);
        self::window($trainer, 4);

        self::assertTrue($trainer->hasActiveAvailability());
    }

    public function testAWindowRegistersItselfOnTheCoachExactlyOnce(): void
    {
        $trainer = new Trainer();
        $window = self::window($trainer, 2);

        // The window's own setter already added it; adding again is a no-op.
        $trainer->addAvailability($window);

        self::assertCount(1, $trainer->getAvailability());
        self::assertSame($trainer, $window->getTrainer());
    }

    public function testRemovingAWindowTakesItOffTheSchedule(): void
    {
        $trainer = new Trainer();
        $morning = self::window($trainer, 2);
        self::window($trainer, 4);

        $trainer->removeAvailability($morning);

        self::assertCount(1, $trainer->getAvailability());
        self::assertFalse($trainer->getAvailability()->contains($morning));
    }

    public function testACoachPrintsAsTheirName(): void
    {
        self::assertSame('Ilze Ozola', (string) (new Trainer())->setFullName('Ilze Ozola'));
    }

    public function testTheSettersChainAndStoreWhatTheyWereGiven(): void
    {
        $trainer = new Trainer();
        $branch = new Branch();
        $bio = TranslatedString::of('Ten years on the platform');

        $returned = $trainer
            ->setSlug('ilze-ozola')
            ->setFullName('Ilze Ozola')
            ->setBio($bio)
            ->setPhotoPath('trainers/ilze.jpg')
            ->setSpecialities(['strength'])
            ->setLanguages(['lv'])
            ->setHourlyRateCents(3500)
            ->setBranch($branch)
            ->setActive(false);

        self::assertSame($trainer, $returned);
        self::assertSame('ilze-ozola', $trainer->getSlug());
        self::assertSame($bio, $trainer->getBio());
        self::assertSame('trainers/ilze.jpg', $trainer->getPhotoPath());
        self::assertSame(['strength'], $trainer->getSpecialities());
        self::assertSame(['lv'], $trainer->getLanguages());
        self::assertSame(3500, $trainer->getHourlyRateCents());
        self::assertSame($branch, $trainer->getBranch());
        self::assertFalse($trainer->isActive());
        self::assertNull($trainer->getId());
    }

    private static function window(Trainer $trainer, int $weekday): TrainerAvailability
    {
        return (new TrainerAvailability($trainer))->setWeekday($weekday);
    }
}
