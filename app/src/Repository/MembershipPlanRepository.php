<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MembershipPlan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MembershipPlan>
 */
class MembershipPlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MembershipPlan::class);
    }

    /**
     * @return list<MembershipPlan>
     */
    public function findActiveOrdered(): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.active = true')
            ->orderBy('p.position', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
