<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Domain\Enum\BookingStatus;
use App\Entity\Booking;
use App\Entity\Trainer;
use App\Entity\User;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * What a booking remembers and what it will let you do to it.
 *
 * The price snapshot is the point of most of this: it is the same promise
 * UserMembership and OrderItem make, and the same one that breaks silently if
 * somebody ever "simplifies" it into a read through the trainer.
 */
final class BookingTest extends TestCase
{
    private const string NOW = '2026-09-01 08:00:00';

    public function testThePriceIsCopiedInAtBookingTime(): void
    {
        $trainer = self::trainer(3500);
        $booking = self::booking($trainer, '+1 day');

        self::assertSame(3500, $booking->getPricePaidCents());

        // The coach puts their rate up. What was already agreed does not move.
        $trainer->setHourlyRateCents(9900);

        self::assertSame(3500, $booking->getPricePaidCents());
    }

    public function testAHalfHourCostsHalfTheHourlyRate(): void
    {
        $trainer = self::trainer(3500);
        $start = self::at('+1 day');

        $booking = new Booking($trainer, new User(), $start, $start->modify('+30 minutes'), self::at('now'));

        self::assertSame(1750, $booking->getPricePaidCents());
        self::assertSame(30, $booking->getDurationMinutes());
    }

    public function testANewBookingIsARequestNobodyHasAnsweredYet(): void
    {
        $booking = self::booking(self::trainer(3500), '+1 day');

        self::assertSame(BookingStatus::REQUESTED, $booking->getStatus());
        self::assertNull($booking->getRespondedAt());
        self::assertTrue($booking->getStatus()->awaitsResponse());
        self::assertTrue($booking->getStatus()->holdsSlot());
    }

    public function testConfirmingAndDecliningBothRecordWhen(): void
    {
        $confirmed = self::booking(self::trainer(3500), '+1 day')->confirm(self::at('now'));

        self::assertSame(BookingStatus::CONFIRMED, $confirmed->getStatus());
        self::assertSame(self::NOW, $confirmed->getRespondedAt()?->format('Y-m-d H:i:s'));
        self::assertTrue($confirmed->getStatus()->holdsSlot());

        $declined = self::booking(self::trainer(3500), '+1 day')->decline(self::at('now'));

        self::assertSame(BookingStatus::DECLINED, $declined->getStatus());

        // A declined hour goes back on sale.
        self::assertFalse($declined->getStatus()->holdsSlot());
    }

    public function testCancellingReleasesTheSlot(): void
    {
        $booking = self::booking(self::trainer(3500), '+1 day')->cancel(self::at('now'));

        self::assertSame(BookingStatus::CANCELLED, $booking->getStatus());
        self::assertFalse($booking->getStatus()->holdsSlot());
    }

    public function testOnlyTheMemberWhoBookedItMayCancelIt(): void
    {
        $mine = new User();
        $somebodyElse = new User();

        $start = self::at('+1 day');
        $booking = new Booking(self::trainer(3500), $mine, $start, $start->modify('+1 hour'), self::at('now'));

        self::assertTrue($booking->isCancellableBy($mine, self::at('now')));
        self::assertFalse($booking->isCancellableBy($somebodyElse, self::at('now')));
    }

    public function testASessionThatHasAlreadyHappenedCannotBeCancelled(): void
    {
        $member = new User();
        $start = self::at('-1 day');
        $booking = new Booking(self::trainer(3500), $member, $start, $start->modify('+1 hour'), self::at('now'));

        self::assertFalse($booking->isUpcoming(self::at('now')));
        self::assertFalse($booking->isCancellableBy($member, self::at('now')));
    }

    public function testASessionAlreadyCalledOffCannotBeCalledOffAgain(): void
    {
        $member = new User();
        $start = self::at('+1 day');
        $booking = new Booking(self::trainer(3500), $member, $start, $start->modify('+1 hour'), self::at('now'));
        $booking->cancel(self::at('now'));

        self::assertFalse($booking->isCancellableBy($member, self::at('now')));
    }

    public function testBothSidesOfTheBookingKnowAboutIt(): void
    {
        $trainer = self::trainer(3500);
        $member = new User();
        $start = self::at('+1 day');

        $booking = new Booking($trainer, $member, $start, $start->modify('+1 hour'), self::at('now'));

        self::assertTrue($trainer->getBookings()->contains($booking));
        self::assertTrue($member->getBookings()->contains($booking));
    }

    public function testBlankNotesAreStoredAsNothingRatherThanAnEmptyString(): void
    {
        $booking = self::booking(self::trainer(3500), '+1 day');

        self::assertNull($booking->setNotes('   ')->getNotes());
        self::assertSame('Bad left knee', $booking->setNotes('  Bad left knee  ')->getNotes());
    }

    /**
     * The slot hold is what the unique index keys on, so it has to track the
     * status exactly. A booking that has been called off must let its hour go,
     * or the coach declines somebody and nobody can ever book that hour again.
     */
    public function testAnOpenBookingHoldsItsSlot(): void
    {
        $booking = self::booking(self::trainer(3500), '+1 day');

        self::assertSame(BookingStatus::REQUESTED, $booking->getStatus());
        self::assertEquals($booking->getStartsAt(), $booking->getHeldSlotAt());

        $booking->confirm(self::at('now'));

        self::assertEquals($booking->getStartsAt(), $booking->getHeldSlotAt());
    }

    public function testADeclinedBookingPutsTheHourBackOnSale(): void
    {
        $booking = self::booking(self::trainer(3500), '+1 day');
        $booking->decline(self::at('now'));

        self::assertNull($booking->getHeldSlotAt());
    }

    public function testACancelledBookingPutsTheHourBackOnSale(): void
    {
        $booking = self::booking(self::trainer(3500), '+1 day');
        $booking->confirm(self::at('now'));
        $booking->cancel(self::at('now'));

        self::assertNull($booking->getHeldSlotAt());
    }

    public function testTheSlotHoldFollowsEveryStatusTheEnumKnows(): void
    {
        foreach (BookingStatus::cases() as $status) {
            $booking = self::booking(self::trainer(3500), '+1 day');
            $booking->setStatus($status);

            if ($status->holdsSlot()) {
                self::assertEquals(
                    $booking->getStartsAt(),
                    $booking->getHeldSlotAt(),
                    sprintf('%s holds its slot, so held_slot_at must be set.', $status->value),
                );

                continue;
            }

            self::assertNull(
                $booking->getHeldSlotAt(),
                sprintf('%s does not hold its slot, so held_slot_at must be null.', $status->value),
            );
        }
    }

    private static function trainer(int $rateCents): Trainer
    {
        return (new Trainer())->setFullName('Test Coach')->setSlug('test-coach')->setHourlyRateCents($rateCents);
    }

    private static function booking(Trainer $trainer, string $when): Booking
    {
        $start = self::at($when);

        return new Booking($trainer, new User(), $start, $start->modify('+1 hour'), self::at('now'));
    }

    private static function at(string $modifier): DateTimeImmutable
    {
        $now = new DateTimeImmutable(self::NOW, new DateTimeZone('UTC'));

        return 'now' === $modifier ? $now : $now->modify($modifier);
    }
}
