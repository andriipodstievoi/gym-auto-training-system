<?php

declare(strict_types=1);

namespace App\Booking;

use DateTimeImmutable;

/**
 * The free hours on one calendar day, in the gym's timezone.
 *
 * Days with nothing free are never built at all, so a template can loop over
 * these without filtering: if the list is empty, the coach is fully booked or
 * does not work that fortnight.
 */
final readonly class SlotDay
{
    /**
     * @param list<Slot> $slots
     */
    public function __construct(
        public DateTimeImmutable $date,
        public array $slots,
    ) {
    }

    /**
     * ISO-8601 weekday, 1 for Monday - the same numbering
     * {@see \App\Entity\TrainerAvailability::$weekday} uses and the same the
     * weekday.1..weekday.7 translations are keyed by.
     */
    public function weekday(): int
    {
        return (int) $this->date->format('N');
    }

    public function count(): int
    {
        return count($this->slots);
    }
}
