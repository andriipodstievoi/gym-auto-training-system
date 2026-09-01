<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProductVariant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProductVariant>
 */
class ProductVariantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductVariant::class);
    }

    /**
     * Hydrates the ids a cart session holds, with their products, in one query.
     *
     * @param list<int> $ids
     *
     * @return array<int, ProductVariant>
     */
    public function findByIdsIndexed(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<ProductVariant> $variants */
        $variants = $this->createQueryBuilder('v')
            ->addSelect('p')
            ->join('v.product', 'p')
            ->andWhere('v.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $indexed = [];

        foreach ($variants as $variant) {
            $id = $variant->getId();

            if (null !== $id) {
                $indexed[$id] = $variant;
            }
        }

        return $indexed;
    }
}
