<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Enum\OrderStatus;
use App\Entity\Order;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Order>
 */
class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    /**
     * Everything this member has ordered, newest first.
     *
     * @return list<Order>
     */
    public function findHistoryFor(User $user): array
    {
        /** @var list<Order> $orders */
        $orders = $this->createQueryBuilder('o')
            ->addSelect('i')
            ->leftJoin('o.items', 'i')
            ->andWhere('o.user = :user')
            ->setParameter('user', $user)
            ->orderBy('o.createdAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $orders;
    }

    /**
     * Orders that were started and never paid for.
     *
     * @return list<Order>
     */
    public function findPendingFor(User $user): array
    {
        /** @var list<Order> $orders */
        $orders = $this->createQueryBuilder('o')
            ->andWhere('o.user = :user')
            ->andWhere('o.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', OrderStatus::PENDING)
            ->getQuery()
            ->getResult();

        return $orders;
    }

    public function findOneByCheckoutSession(string $sessionId): ?Order
    {
        /** @var Order|null $order */
        $order = $this->createQueryBuilder('o')
            ->addSelect('i', 'u')
            ->leftJoin('o.items', 'i')
            ->join('o.user', 'u')
            ->andWhere('o.stripeCheckoutSessionId = :session')
            ->setParameter('session', $sessionId)
            ->getQuery()
            ->getOneOrNullResult();

        return $order;
    }

    public function findOneByReference(string $reference): ?Order
    {
        /** @var Order|null $order */
        $order = $this->createQueryBuilder('o')
            ->addSelect('i', 'u')
            ->leftJoin('o.items', 'i')
            ->join('o.user', 'u')
            ->andWhere('o.reference = :reference')
            ->setParameter('reference', $reference)
            ->getQuery()
            ->getOneOrNullResult();

        return $order;
    }
}
