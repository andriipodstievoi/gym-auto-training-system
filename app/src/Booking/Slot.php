<?php

declare(strict_types=1);

namespace App\Booking;

use DateTimeImmutable;

/**
 * One bookable hour, as the slot picker sees it.
 *
 * Read-only on purpose: this is a projection of availability minus bookings,
 * not something anybody saves. Nothing here has an id, because a free hour is
 * not a row until somebody books it.
 *
 * The instants are UTC, which is what goes in the database; {@see $localStart}
 * is the same moment in the gym's own timezone and is what a member reads.
 */
final readonly class Slot
{
    public function __construct(
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
        public DateTimeImmutable $localStart,
        public int $priceCents,
    ) {
    }

    /**
     * How a slot is named in a form field and matched against a booking. UTC,
     * to the minute - a session is on the hour, so seconds carry no meaning.
     */
    public function key(): string
    {
        return $this->startsAt->format('Y-m-d H:i');
    }

    public function durationMinutes(): int
    {
        return (int) round(($this->endsAt->getTimestamp() - $this->startsAt->getTimestamp()) / 60);
    }

    /**
     * The label on the button: local time, because nobody turns up to the gym
     * in UTC.
     */
    public function label(): string
    {
        return $this->localStart->format('H:i');
    }
}
