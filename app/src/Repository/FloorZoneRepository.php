<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Branch;
use App\Entity\FloorZone;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FloorZone>
 */
class FloorZoneRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FloorZone::class);
    }

    /**
     * Zones of one branch with their equipment, ready for the clickable plan.
     *
     * @return list<FloorZone>
     */
    public function findForBranchWithEquipment(Branch $branch): array
    {
        return $this->createQueryBuilder('z')
            ->addSelect('e')
            ->leftJoin('z.equipment', 'e')
            ->andWhere('z.branch = :branch')
            ->setParameter('branch', $branch)
            ->orderBy('z.position', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
