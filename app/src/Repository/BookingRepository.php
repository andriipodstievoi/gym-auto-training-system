<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Enum\BookingStatus;
use App\Entity\Booking;
use App\Entity\Trainer;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Booking>
 */
class BookingRepository extends ServiceEntityRepository
{
    /**
     * Bound as backing strings: Doctrine converts a single enum parameter, but
     * an array of them goes to the driver as objects.
     *
     * @var list<string>
     */
    private const array HELD_STATUSES = [BookingStatus::REQUESTED->value, BookingStatus::CONFIRMED->value];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Booking::class);
    }

    /**
     * The hours in a window that are already spoken for, keyed by the slot's
     * start time so the slot finder can look one up in constant time.
     *
     * Declined and cancelled sessions are deliberately absent: those hours go
     * back on sale.
     *
     * @return array<string, Booking>
     */
    public function findHeldSlots(Trainer $trainer, DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        /** @var list<Booking> $bookings */
        $bookings = $this->createQueryBuilder('b')
            ->andWhere('b.trainer = :trainer')
            ->andWhere('b.status IN (:held)')
            ->andWhere('b.startsAt >= :from')
            ->andWhere('b.startsAt < :until')
            ->setParameter('trainer', $trainer)
            ->setParameter('held', self::HELD_STATUSES)
            ->setParameter('from', $from)
            ->setParameter('until', $until)
            ->getQuery()
            ->getResult();

        $held = [];

        foreach ($bookings as $booking) {
            $held[$booking->getStartsAt()->format('Y-m-d H:i')] = $booking;
        }

        return $held;
    }

    /**
     * Whether this exact hour is still held by somebody. The database index is
     * the real guard; this is the polite refusal before we get there.
     */
    public function isSlotHeld(Trainer $trainer, DateTimeImmutable $startsAt): bool
    {
        $count = $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->andWhere('b.trainer = :trainer')
            ->andWhere('b.startsAt = :startsAt')
            ->andWhere('b.status IN (:held)')
            ->setParameter('trainer', $trainer)
            ->setParameter('startsAt', $startsAt)
            ->setParameter('held', self::HELD_STATUSES)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    /**
     * A member's own sessions, the ones still to come first.
     *
     * @return list<Booking>
     */
    public function findForMember(User $user, DateTimeImmutable $now): array
    {
        /** @var list<Booking> $bookings */
        $bookings = $this->createQueryBuilder('b')
            ->addSelect('t')
            ->addSelect('CASE WHEN b.startsAt > :now THEN 0 ELSE 1 END AS HIDDEN stillToCome')
            ->join('b.trainer', 't')
            ->andWhere('b.user = :user')
            ->setParameter('user', $user)
            ->setParameter('now', $now)
            ->orderBy('stillToCome', 'ASC')
            ->addOrderBy('b.startsAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $bookings;
    }

    /**
     * The coach's diary: everything still ahead of them, the requests they owe
     * an answer to first.
     *
     * @return list<Booking>
     */
    public function findUpcomingForTrainer(Trainer $trainer, DateTimeImmutable $now): array
    {
        /** @var list<Booking> $bookings */
        $bookings = $this->createQueryBuilder('b')
            ->addSelect('u')
            ->addSelect('CASE WHEN b.status = :requested THEN 0 ELSE 1 END AS HIDDEN awaitingFirst')
            ->join('b.user', 'u')
            ->andWhere('b.trainer = :trainer')
            ->andWhere('b.startsAt >= :now')
            ->andWhere('b.status IN (:live)')
            ->setParameter('trainer', $trainer)
            ->setParameter('now', $now)
            ->setParameter('live', self::HELD_STATUSES)
            ->setParameter('requested', BookingStatus::REQUESTED->value)
            ->orderBy('awaitingFirst', 'ASC')
            ->addOrderBy('b.startsAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $bookings;
    }
}
