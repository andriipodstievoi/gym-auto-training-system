<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Domain\Enum\EquipmentType;
use App\Domain\Enum\Limitation;
use App\Domain\Enum\MuscleGroup;
use App\Entity\Exercise;
use App\Repository\ExerciseRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration coverage for the query the M6 rule engine will lean on, and for
 * the TranslatedString round trip through a real MySQL JSON column.
 *
 * Expects the test database to be migrated and seeded:
 *   bin/console doctrine:migrations:migrate --env=test
 *   bin/console doctrine:fixtures:load --env=test
 */
final class ExerciseRepositoryTest extends KernelTestCase
{
    private ExerciseRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = self::getContainer()->get(ExerciseRepository::class);
    }

    public function testLibraryIsSeeded(): void
    {
        self::assertGreaterThanOrEqual(30, count($this->repository->findAllOrdered()));
    }

    public function testTranslationsSurviveTheJsonColumn(): void
    {
        $squat = $this->repository->findOneBy(['slug' => 'barbell-back-squat']);

        self::assertInstanceOf(Exercise::class, $squat);
        self::assertSame('Barbell back squat', $squat->getName()->get('en'));
        self::assertSame('Pietupiens ar stieni', $squat->getName()->get('lv'));
        self::assertSame('Присед со штангой', $squat->getName()->get('ru'));
    }

    public function testSelectionIsLimitedToAvailableEquipment(): void
    {
        $bodyweightOnly = $this->repository->findSelectable([EquipmentType::BODYWEIGHT]);

        self::assertNotEmpty($bodyweightOnly);

        foreach ($bodyweightOnly as $exercise) {
            self::assertSame(EquipmentType::BODYWEIGHT, $exercise->getEquipment());
        }
    }

    public function testHomeSetupStillHasSomethingForEveryMajorMuscle(): void
    {
        $available = EquipmentType::homeBasic();

        foreach ([MuscleGroup::CHEST, MuscleGroup::BACK, MuscleGroup::QUADS, MuscleGroup::CORE] as $muscle) {
            self::assertNotEmpty(
                $this->repository->findSelectable($available, [], $muscle),
                sprintf('A home setup has no option for %s.', $muscle->value),
            );
        }
    }

    public function testContraindicatedMovementsAreExcluded(): void
    {
        $slugs = array_map(
            static fn (Exercise $exercise): string => $exercise->getSlug(),
            $this->repository->findSelectable([EquipmentType::BARBELL], [Limitation::LOWER_BACK]),
        );

        self::assertNotContains('conventional-deadlift', $slugs);
        self::assertNotContains('romanian-deadlift', $slugs);
        self::assertNotContains('barbell-row', $slugs);

        // A barbell movement that does not load the lower back stays available.
        self::assertContains('barbell-bench-press', $slugs);
    }

    public function testShoulderLimitationRemovesOverheadAndBenchPressing(): void
    {
        $slugs = array_map(
            static fn (Exercise $exercise): string => $exercise->getSlug(),
            $this->repository->findSelectable(
                [EquipmentType::BARBELL, EquipmentType::DUMBBELL],
                [Limitation::SHOULDER],
            ),
        );

        self::assertNotContains('overhead-press', $slugs);
        self::assertNotContains('barbell-bench-press', $slugs);
        self::assertContains('barbell-back-squat', $slugs);
    }

    public function testInactiveExercisesNeverAppear(): void
    {
        foreach ($this->repository->findSelectable([]) as $exercise) {
            self::assertTrue($exercise->isActive());
        }
    }
}
