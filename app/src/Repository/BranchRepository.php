<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Branch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Branch>
 */
class BranchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Branch::class);
    }

    /**
     * Every branch shown on the public map, with zones already loaded so the
     * map and the floor plan render from one query.
     *
     * @return list<Branch>
     */
    public function findActiveWithZones(): array
    {
        return $this->createQueryBuilder('b')
            ->addSelect('z')
            ->leftJoin('b.floorZones', 'z')
            ->andWhere('b.active = true')
            ->orderBy('b.name', 'ASC')
            ->addOrderBy('z.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneActiveBySlug(string $slug): ?Branch
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.slug = :slug')
            ->andWhere('b.active = true')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
