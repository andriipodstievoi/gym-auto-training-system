<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Message;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Message>
 */
class MessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * Everything waiting for this account across every thread they are in.
     * Their own messages never count, however long they go unopened.
     */
    public function countUnreadFor(User $user): int
    {
        $count = $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->join('m.conversation', 'c')
            ->join('c.trainer', 't')
            ->leftJoin('t.user', 'tu')
            ->andWhere('m.readAt IS NULL')
            ->andWhere('m.sender != :user')
            ->andWhere('c.member = :user OR tu = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }
}
