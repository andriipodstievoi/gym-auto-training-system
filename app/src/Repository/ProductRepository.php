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
            ->addSelect('c', 'v')
            ->leftJoin('p.category', 'c')
            ->leftJoin('p.variants', 'v')
            ->andWhere('p.active = true')
            ->orderBy('p.slug', 'ASC')
            ->addOrderBy('v.position', 'ASC');

        if (null !== $category) {
            $qb->andWhere('p.category = :category')->setParameter('category', $category);
        }

        /** @var list<Product> $products */
        $products = $qb->getQuery()->getResult();

        return $products;
    }

    public function findOneActiveBySlug(string $slug): ?Product
    {
        /** @var Product|null $product */
        $product = $this->createQueryBuilder('p')
            ->addSelect('c', 'v')
            ->leftJoin('p.category', 'c')
            ->leftJoin('p.variants', 'v')
            ->andWhere('p.slug = :slug')
            ->andWhere('p.active = true')
            ->setParameter('slug', $slug)
            ->addOrderBy('v.position', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();

        return $product;
    }

    /**
     * Hydrates the ids a cart session holds, in one query.
     *
     * @param list<int> $ids
     *
     * @return array<int, Product>
     */
    public function findByIdsIndexed(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<Product> $products */
        $products = $this->createQueryBuilder('p')
            ->addSelect('v')
            ->leftJoin('p.variants', 'v')
            ->andWhere('p.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->addOrderBy('v.position', 'ASC')
            ->getQuery()
            ->getResult();

        $indexed = [];

        foreach ($products as $product) {
            $id = $product->getId();

            if (null !== $id) {
                $indexed[$id] = $product;
            }
        }

        return $indexed;
    }
}
