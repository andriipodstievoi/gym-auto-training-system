<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TrainingPlan;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrainingPlan>
 */
class TrainingPlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrainingPlan::class);
    }

    /**
     * A member's programmes, newest first - which is the order that matters,
     * because the latest one is the one they are training on.
     *
     * The assessment is joined in because the list shows what was asked for
     * next to what came back, and four rows should not be four extra queries.
     *
     * @return list<TrainingPlan>
     */
    public function findForMember(User $user): array
    {
        /** @var list<TrainingPlan> $plans */
        $plans = $this->createQueryBuilder('p')
            ->addSelect('a')
            ->join('p.assessment', 'a')
            ->andWhere('p.user = :user')
            ->setParameter('user', $user)
            ->orderBy('p.createdAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $plans;
    }
}
