<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Trainer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Trainer>
 */
class TrainerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Trainer::class);
    }

    /**
     * @return list<Trainer>
     */
    public function findActiveWithBranch(): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('b')
            ->leftJoin('t.branch', 'b')
            ->andWhere('t.active = true')
            ->orderBy('t.fullName', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
