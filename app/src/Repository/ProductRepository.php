<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;
use App\Entity\ProductCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /**
     * @return list<Product>
     */
    public function findActiveInCategory(?ProductCategory $category = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->addSelect('c')
            ->leftJoin('p.category', 'c')
            ->andWhere('p.active = true')
            ->orderBy('p.slug', 'ASC');

        if (null !== $category) {
            $qb->andWhere('p.category = :category')->setParameter('category', $category);
        }

        return $qb->getQuery()->getResult();
    }
}
