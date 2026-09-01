<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\Trainer;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Conversation>
 */
class ConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversation::class);
    }

    public function findOnePair(Trainer $trainer, User $member): ?Conversation
    {
        /** @var Conversation|null $conversation */
        $conversation = $this->createQueryBuilder('c')
            ->andWhere('c.trainer = :trainer')
            ->andWhere('c.member = :member')
            ->setParameter('trainer', $trainer)
            ->setParameter('member', $member)
            ->getQuery()
            ->getOneOrNullResult();

        return $conversation;
    }

    /**
     * Every thread this account is in, whichever side of it they are on, most
     * recently written to first.
     *
     * @return list<Conversation>
     */
    public function findForParticipant(User $user): array
    {
        /** @var list<Conversation> $conversations */
        $conversations = $this->createQueryBuilder('c')
            ->addSelect('t', 'm', 'msg')
            ->join('c.trainer', 't')
            ->join('c.member', 'm')
            ->leftJoin('c.messages', 'msg')
            ->leftJoin('t.user', 'tu')
            ->andWhere('c.member = :user OR tu = :user')
            ->setParameter('user', $user)
            ->orderBy('c.lastMessageAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $conversations;
    }
}
