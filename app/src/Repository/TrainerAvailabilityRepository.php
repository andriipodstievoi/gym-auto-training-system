<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Trainer;
use App\Entity\TrainerAvailability;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrainerAvailability>
 */
class TrainerAvailabilityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrainerAvailability::class);
    }

    /**
     * The hours this coach currently works, in the order a week runs.
     *
     * @return list<TrainerAvailability>
     */
    public function findActiveFor(Trainer $trainer): array
    {
        /** @var list<TrainerAvailability> $windows */
        $windows = $this->createQueryBuilder('a')
            ->andWhere('a.trainer = :trainer')
            ->andWhere('a.active = true')
            ->setParameter('trainer', $trainer)
            ->orderBy('a.weekday', 'ASC')
            ->addOrderBy('a.startTime', 'ASC')
            ->getQuery()
            ->getResult();

        return $windows;
    }

    /**
     * Everything on the coach's own availability page, paused windows included
     * - they are theirs to switch back on.
     *
     * @return list<TrainerAvailability>
     */
    public function findAllFor(Trainer $trainer): array
    {
        /** @var list<TrainerAvailability> $windows */
        $windows = $this->createQueryBuilder('a')
            ->andWhere('a.trainer = :trainer')
            ->setParameter('trainer', $trainer)
            ->orderBy('a.weekday', 'ASC')
            ->addOrderBy('a.startTime', 'ASC')
            ->getQuery()
            ->getResult();

        return $windows;
    }
}
