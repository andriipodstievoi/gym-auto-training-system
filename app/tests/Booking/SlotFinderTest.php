<?php

declare(strict_types=1);

namespace App\Tests\Booking;

use App\Booking\SlotFinder;
use App\Entity\Booking;
use App\Entity\Trainer;
use App\Entity\TrainerAvailability;
use App\Entity\User;
use App\Repository\BookingRepository;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Slot generation, on a frozen clock.
 *
 * Every one of these would be flaky against the wall clock: "the next two
 * weeks" is a different fortnight every time it runs, and the lead-time rule
 * changes which slots survive depending on the hour of the day the suite
 * happens to start. The clock is injected precisely so it can be nailed down.
 *
 * 1 September 2026 is a Tuesday, and Riga is three hours ahead of UTC in
 * September, which is why 08:00 UTC below is 11:00 in the gym.
 */
final class SlotFinderTest extends TestCase
{
    private const string NOW_UTC = '2026-09-01 08:00:00';

    public function testAWindowBecomesHourlySlotsInTheGymTimezone(): void
    {
        $trainer = self::trainerWorking(2, '09:00', '17:00');
        $finder = self::finder([]);

        $days = $finder->findForTrainer($trainer, 1);

        self::assertCount(1, $days);
        self::assertSame(2, $days[0]->weekday());

        // 09:00 to 17:00 is eight hours, but the first bookable one today is
        // 13:00 - see the lead-time test below.
        self::assertSame(['13:00', '14:00', '15:00', '16:00'], self::localTimes($days[0]->slots));
    }

    public function testSlotsAreStoredInUtcAndShownInRigaTime(): void
    {
        $trainer = self::trainerWorking(2, '09:00', '17:00');

        $slots = self::finder([])->findNextSlots($trainer, 1, 1);

        self::assertCount(1, $slots);
        self::assertSame('2026-09-01 10:00', $slots[0]->startsAt->format('Y-m-d H:i'));
        self::assertSame('UTC', $slots[0]->startsAt->getTimezone()->getName());
        self::assertSame('13:00', $slots[0]->localStart->format('H:i'));
        self::assertSame(60, $slots[0]->durationMinutes());
    }

    /**
     * Everything before now, and everything within two hours of it, is gone.
     */
    public function testPastHoursAndTheLeadTimeAreExcluded(): void
    {
        $trainer = self::trainerWorking(2, '09:00', '17:00');

        $days = self::finder([])->findForTrainer($trainer, 1);

        // 11:00 is now, so 09:00 and 10:00 are in the past; 11:00 and 12:00
        // are inside the two-hour lead time.
        self::assertNotContains('09:00', self::localTimes($days[0]->slots));
        self::assertNotContains('11:00', self::localTimes($days[0]->slots));
        self::assertNotContains('12:00', self::localTimes($days[0]->slots));
        self::assertContains('13:00', self::localTimes($days[0]->slots));
    }

    public function testAnHourSomebodyAlreadyHoldsIsNotOffered(): void
    {
        $trainer = self::trainerWorking(2, '09:00', '17:00');

        // 14:00 in Riga is 11:00 UTC, which is how a booking is stored.
        $taken = new DateTimeImmutable('2026-09-01 11:00', new DateTimeZone('UTC'));
        $finder = self::finder([
            '2026-09-01 11:00' => new Booking($trainer, new User(), $taken, $taken->modify('+1 hour')),
        ]);

        $days = $finder->findForTrainer($trainer, 1);

        self::assertSame(['13:00', '15:00', '16:00'], self::localTimes($days[0]->slots));
    }

    /**
     * Days the coach does not work never appear at all, so a template can loop
     * over the result without filtering.
     */
    public function testDaysWithNothingFreeAreLeftOutEntirely(): void
    {
        $trainer = self::trainerWorking(2, '09:00', '17:00');

        $days = self::finder([])->findForTrainer($trainer, 8);

        // Tuesday this week and Tuesday next: nothing in between.
        self::assertCount(2, $days);
        self::assertSame('2026-09-01', $days[0]->date->format('Y-m-d'));
        self::assertSame('2026-09-08', $days[1]->date->format('Y-m-d'));
    }

    public function testAPausedWindowGeneratesNothing(): void
    {
        $trainer = self::trainer();

        (new TrainerAvailability($trainer))
            ->setWeekday(2)
            ->setStartTime(new DateTimeImmutable('09:00'))
            ->setEndTime(new DateTimeImmutable('17:00'))
            ->setActive(false);

        self::assertSame([], self::finder([])->findForTrainer($trainer, 7));
    }

    public function testACoachWithNoHoursHasNoSlots(): void
    {
        self::assertSame([], self::finder([])->findForTrainer(self::trainer(), 14));
    }

    public function testASlotCostsAnHourOfTheCoachTime(): void
    {
        $trainer = self::trainerWorking(2, '09:00', '17:00');
        $trainer->setHourlyRateCents(4000);

        $slots = self::finder([])->findNextSlots($trainer, 1, 1);

        self::assertSame(4000, $slots[0]->priceCents);
    }

    public function testABookableSlotCanBeLookedUpByItsInstant(): void
    {
        $trainer = self::trainerWorking(2, '09:00', '17:00');
        $finder = self::finder([]);

        $wanted = new DateTimeImmutable('2026-09-01 10:00', new DateTimeZone('UTC'));

        self::assertNotNull($finder->findBookableSlot($trainer, $wanted, 1));

        // An hour inside the lead time is not bookable, however it is asked for.
        $tooSoon = new DateTimeImmutable('2026-09-01 09:00', new DateTimeZone('UTC'));

        self::assertNull($finder->findBookableSlot($trainer, $tooSoon, 1));
    }

    /**
     * @param array<string, Booking> $held
     */
    private function finder(array $held): SlotFinder
    {
        $bookings = $this->createStub(BookingRepository::class);
        $bookings->method('findHeldSlots')->willReturn($held);

        return new SlotFinder(new MockClock(self::NOW_UTC, new DateTimeZone('UTC')), $bookings);
    }

    private static function trainer(): Trainer
    {
        return (new Trainer())->setFullName('Test Coach')->setSlug('test-coach')->setHourlyRateCents(3500);
    }

    private static function trainerWorking(int $weekday, string $from, string $to): Trainer
    {
        $trainer = self::trainer();

        (new TrainerAvailability($trainer))
            ->setWeekday($weekday)
            ->setStartTime(new DateTimeImmutable($from))
            ->setEndTime(new DateTimeImmutable($to));

        return $trainer;
    }

    /**
     * @param list<\App\Booking\Slot> $slots
     *
     * @return list<string>
     */
    private static function localTimes(array $slots): array
    {
        return array_map(static fn (\App\Booking\Slot $slot): string => $slot->localStart->format('H:i'), $slots);
    }
}
