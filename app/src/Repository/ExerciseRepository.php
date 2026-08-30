<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Enum\EquipmentType;
use App\Domain\Enum\Limitation;
use App\Domain\Enum\MuscleGroup;
use App\Entity\Exercise;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Exercise>
 */
class ExerciseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Exercise::class);
    }

    /**
     * The pool of movements a member can actually perform.
     *
     * Equipment and muscle filtering happen in SQL because they are indexed.
     * Contraindications are filtered in PHP: they live in a JSON column, and
     * matching them in SQL would need vendor-specific JSON functions for a
     * library that is only a few dozen rows long.
     *
     * @param list<EquipmentType> $available
     * @param list<Limitation>    $limitations
     *
     * @return list<Exercise>
     */
    public function findSelectable(
        array $available,
        array $limitations = [],
        ?MuscleGroup $primaryMuscle = null,
    ): array {
        $qb = $this->createQueryBuilder('e')
            ->andWhere('e.active = true')
            ->orderBy('e.difficulty', 'ASC')
            ->addOrderBy('e.slug', 'ASC');

        if ([] !== $available) {
            $qb->andWhere('e.equipment IN (:equipment)')
                ->setParameter('equipment', array_map(
                    static fn (EquipmentType $type): string => $type->value,
                    $available,
                ));
        }

        if (null !== $primaryMuscle) {
            $qb->andWhere('e.primaryMuscle = :muscle')
                ->setParameter('muscle', $primaryMuscle->value);
        }

        /** @var list<Exercise> $exercises */
        $exercises = $qb->getQuery()->getResult();

        if ([] === $limitations) {
            return $exercises;
        }

        return array_values(array_filter(
            $exercises,
            static fn (Exercise $exercise): bool => !$exercise->isContraindicatedFor($limitations),
        ));
    }

    /**
     * @return list<Exercise>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('e')
            ->orderBy('e.primaryMuscle', 'ASC')
            ->addOrderBy('e.slug', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
