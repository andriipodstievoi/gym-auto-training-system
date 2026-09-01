<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Branch;
use App\Entity\Trainer;
use App\Entity\User;
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

    /**
     * The coaching staff of one branch, for that branch's public page.
     *
     * @return list<Trainer>
     */
    public function findActiveForBranch(Branch $branch): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.branch = :branch')
            ->andWhere('t.active = true')
            ->setParameter('branch', $branch)
            ->orderBy('t.fullName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The coach profile this login belongs to, if any.
     *
     * There is no ROLE_TRAINER and no second firewall: "is a coach" is simply
     * "some trainer row points at this account", which is one fact in one
     * place rather than a role that can drift out of step with it.
     */
    public function findOneByUser(User $user): ?Trainer
    {
        /** @var Trainer|null $trainer */
        $trainer = $this->createQueryBuilder('t')
            ->addSelect('b')
            ->leftJoin('t.branch', 'b')
            ->andWhere('t.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();

        return $trainer;
    }

    public function findOneActiveBySlug(string $slug): ?Trainer
    {
        return $this->createQueryBuilder('t')
            ->addSelect('b')
            ->leftJoin('t.branch', 'b')
            ->andWhere('t.slug = :slug')
            ->andWhere('t.active = true')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
