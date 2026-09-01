<?php

declare(strict_types=1);

namespace App\Booking;

use App\Entity\Trainer;
use App\Repository\BookingRepository;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Clock\ClockInterface;

/**
 * Turns a coach's weekly hours into the concrete hours a member can click.
 *
 * A service rather than controller code because the interesting part is
 * arithmetic, and arithmetic is worth testing on its own. The clock is
 * injected for the same reason: a slot test that reads the wall clock passes
 * in the morning and fails at half past eleven at night.
 *
 * Timezone. The gym is in Riga and its opening hours are Riga wall-clock
 * hours, so windows are expanded in Europe/Riga. Everything that leaves this
 * class - and everything that reaches the database - is UTC, because that is
 * the only way a stored instant survives the two clock changes a year. The
 * local time rides along on each slot purely so a template can print it.
 */
final readonly class SlotFinder
{
    /**
     * Where the gym is, and therefore what a coach means by "nine in the
     * morning".
     */
    public const string TIMEZONE = 'Europe/Riga';

    /**
     * Sessions are an hour. Nothing in the model insists on it - a booking
     * stores its own start and end - but a picker offering ragged lengths is a
     * worse picker.
     */
    public const int SLOT_MINUTES = 60;

    /**
     * Nobody may book an hour that starts in the next two: a coach has to
     * read the request and get to the gym.
     */
    public const int LEAD_TIME_MINUTES = 120;

    public const int DEFAULT_DAYS = 14;

    public function __construct(
        private ClockInterface $clock,
        private BookingRepository $bookings,
    ) {
    }

    /**
     * Every bookable hour for this coach over the next $days, grouped by day.
     *
     * @return list<SlotDay>
     */
    public function findForTrainer(Trainer $trainer, int $days = self::DEFAULT_DAYS): array
    {
        $days = max(1, $days);
        $zone = new DateTimeZone(self::TIMEZONE);
        $utc = new DateTimeZone('UTC');

        $now = $this->clock->now()->setTimezone($zone);
        $earliest = $now->add(new DateInterval('PT'.self::LEAD_TIME_MINUTES.'M'));
        $firstDay = $now->setTime(0, 0);

        // One query for the whole range rather than one per day. The range is
        // deliberately generous at both ends: the boundary days straddle
        // midnight in two timezones and an off-by-one hour here would hide a
        // booking rather than show a taken slot.
        $held = $this->bookings->findHeldSlots(
            $trainer,
            $firstDay->setTimezone($utc)->sub(new DateInterval('P1D')),
            $firstDay->add(new DateInterval('P'.($days + 1).'D'))->setTimezone($utc),
        );

        $windows = [];

        foreach ($trainer->getAvailability() as $window) {
            if ($window->isActive()) {
                $windows[$window->getWeekday()][] = $window;
            }
        }

        $result = [];

        for ($offset = 0; $offset < $days; ++$offset) {
            $date = $firstDay->add(new DateInterval('P'.$offset.'D'));
            $weekday = (int) $date->format('N');

            $slots = [];

            foreach ($windows[$weekday] ?? [] as $window) {
                $startMinutes = self::minutesOfDay($window->getStartTime());
                $endMinutes = self::minutesOfDay($window->getEndTime());

                for ($minute = $startMinutes; $minute + self::SLOT_MINUTES <= $endMinutes; $minute += self::SLOT_MINUTES) {
                    $localStart = $date->setTime(intdiv($minute, 60), $minute % 60);
                    $startsAt = $localStart->setTimezone($utc);

                    // Past hours, and hours too close to now to be organised.
                    if ($localStart < $earliest) {
                        continue;
                    }

                    if (isset($held[$startsAt->format('Y-m-d H:i')])) {
                        continue;
                    }

                    $endsAt = $startsAt->add(new DateInterval('PT'.self::SLOT_MINUTES.'M'));

                    $slots[$startsAt->format('Y-m-d H:i')] = new Slot(
                        $startsAt,
                        $endsAt,
                        $localStart,
                        self::priceFor($trainer->getHourlyRateCents()),
                    );
                }
            }

            if ([] === $slots) {
                continue;
            }

            // Two overlapping windows on one day would otherwise offer the
            // same hour twice; the key above deduplicates and this restores
            // chronological order.
            ksort($slots);

            $result[] = new SlotDay($date, array_values($slots));
        }

        return $result;
    }

    /**
     * The first few free hours, flattened - what a coach's public profile
     * shows without turning into a calendar.
     *
     * @return list<Slot>
     */
    public function findNextSlots(Trainer $trainer, int $limit = 4, int $days = self::DEFAULT_DAYS): array
    {
        $slots = [];

        foreach ($this->findForTrainer($trainer, $days) as $day) {
            foreach ($day->slots as $slot) {
                $slots[] = $slot;

                if (count($slots) >= $limit) {
                    return $slots;
                }
            }
        }

        return $slots;
    }

    /**
     * The slot starting at this exact instant, if it is still bookable.
     *
     * Booking re-runs this rather than trusting the hidden field it posted:
     * between rendering the picker and clicking a button, somebody else may
     * have taken the hour or the coach may have dropped the window.
     */
    public function findBookableSlot(Trainer $trainer, DateTimeImmutable $startsAt, int $days = self::DEFAULT_DAYS): ?Slot
    {
        $wanted = $startsAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i');

        foreach ($this->findForTrainer($trainer, $days) as $day) {
            foreach ($day->slots as $slot) {
                if ($slot->key() === $wanted) {
                    return $slot;
                }
            }
        }

        return null;
    }

    /**
     * An hour of this coach's time, which is what a slot costs.
     */
    private static function priceFor(int $hourlyRateCents): int
    {
        return (int) round($hourlyRateCents * self::SLOT_MINUTES / 60);
    }

    private static function minutesOfDay(DateTimeImmutable $time): int
    {
        return (int) $time->format('H') * 60 + (int) $time->format('i');
    }
}
