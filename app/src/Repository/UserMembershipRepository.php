<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Enum\MembershipStatus;
use App\Entity\User;
use App\Entity\UserMembership;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserMembership>
 */
class UserMembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserMembership::class);
    }

    /**
     * The membership that currently opens the door, if there is one.
     *
     * Cancelled counts until the paid period runs out, which is why the status
     * filter is a list rather than "active".
     */
    public function findCurrentFor(User $user, ?DateTimeImmutable $now = null): ?UserMembership
    {
        return $this->createQueryBuilder('m')
            ->addSelect('p')
            ->join('m.plan', 'p')
            ->andWhere('m.user = :user')
            ->andWhere('m.status IN (:statuses)')
            ->andWhere('m.endsAt IS NULL OR m.endsAt > :now')
            ->setParameter('user', $user)
            ->setParameter('statuses', [MembershipStatus::ACTIVE, MembershipStatus::CANCELLED])
            ->setParameter('now', $now ?? new DateTimeImmutable())
            ->orderBy('m.endsAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Everything this member has ever bought, newest first.
     *
     * @return list<UserMembership>
     */
    public function findHistoryFor(User $user): array
    {
        return $this->createQueryBuilder('m')
            ->addSelect('p')
            ->join('m.plan', 'p')
            ->andWhere('m.user = :user')
            ->setParameter('user', $user)
            ->orderBy('m.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Purchases that were started and never paid for.
     *
     * @return list<UserMembership>
     */
    public function findPendingFor(User $user): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.user = :user')
            ->andWhere('m.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', MembershipStatus::PENDING)
            ->getQuery()
            ->getResult();
    }

    public function findOneByCheckoutSession(string $sessionId): ?UserMembership
    {
        return $this->createQueryBuilder('m')
            ->addSelect('p', 'u')
            ->join('m.plan', 'p')
            ->join('m.user', 'u')
            ->andWhere('m.stripeCheckoutSessionId = :session')
            ->setParameter('session', $sessionId)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
