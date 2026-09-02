<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Domain\Enum\EquipmentType;
use App\Domain\Enum\Limitation;
use App\Domain\Enum\MovementPattern;
use App\Domain\Enum\MuscleGroup;
use App\Domain\TranslatedString;
use App\Entity\Exercise;
use PHPUnit\Framework\TestCase;

/**
 * One movement in the library, as the plan generator sees it.
 *
 * Two questions decide whether a movement can go in a programme at all: does
 * it load a joint the member has declared, and is it compound enough to open
 * a session with. Both are answered here rather than in SQL, so both are
 * worth pinning - especially the contraindication check, where a false
 * negative puts a barbell on an injured back.
 */
final class ExerciseTest extends TestCase
{
    public function testANewMovementIsAnEasyBarbellPushNobodyHasRuledOut(): void
    {
        $exercise = new Exercise();

        self::assertSame(MuscleGroup::CHEST, $exercise->getPrimaryMuscle());
        self::assertSame(MovementPattern::HORIZONTAL_PUSH, $exercise->getPattern());
        self::assertSame(EquipmentType::BARBELL, $exercise->getEquipment());
        self::assertSame(1, $exercise->getDifficulty());
        self::assertTrue($exercise->isActive());
        self::assertSame([], $exercise->getSecondaryMuscles());
        self::assertSame([], $exercise->getContraindications());
        self::assertTrue($exercise->getName()->isEmpty());
        self::assertNull($exercise->getId());
    }

    public function testAMovementThatLoadsADeclaredJointIsRuledOut(): void
    {
        $deadlift = self::exercise()->setContraindications(['lower_back', 'knee']);

        self::assertTrue($deadlift->isContraindicatedFor([Limitation::LOWER_BACK]));
        self::assertTrue($deadlift->isContraindicatedFor([Limitation::SHOULDER, Limitation::KNEE]));
    }

    public function testAMovementThatLoadsNothingDeclaredStaysOnTheMenu(): void
    {
        $deadlift = self::exercise()->setContraindications(['lower_back', 'knee']);

        self::assertFalse($deadlift->isContraindicatedFor([Limitation::SHOULDER]));
        self::assertFalse($deadlift->isContraindicatedFor([Limitation::ELBOW, Limitation::NECK]));
    }

    public function testAMemberWhoDeclaredNothingIsOfferedEverything(): void
    {
        self::assertFalse(self::exercise()->setContraindications(['lower_back'])->isContraindicatedFor([]));
    }

    public function testAMovementWithNoContraindicationsIsNeverRuledOut(): void
    {
        self::assertFalse(self::exercise()->isContraindicatedFor(Limitation::cases()));
    }

    /**
     * The column is plain JSON of backing values, so the match has to be on
     * the value and it has to be exact - a loose comparison would let an
     * unrelated string rule a movement out, or worse, fail to.
     */
    public function testTheJointMatchIsExactRatherThanApproximate(): void
    {
        $exercise = self::exercise()->setContraindications(['lower_back']);

        self::assertTrue($exercise->isContraindicatedFor([Limitation::LOWER_BACK]));
        self::assertFalse($exercise->isContraindicatedFor([Limitation::SHOULDER]));

        // Case matters: the column holds backing values, not labels.
        $exercise->setContraindications(['LOWER_BACK']);
        self::assertFalse($exercise->isContraindicatedFor([Limitation::LOWER_BACK]));
    }

    public function testEveryLimitationTheEnumKnowsCanRuleAMovementOut(): void
    {
        foreach (Limitation::cases() as $limitation) {
            $exercise = self::exercise()->setContraindications([$limitation->value]);

            self::assertTrue(
                $exercise->isContraindicatedFor([$limitation]),
                $limitation->value.' must be able to rule a movement out.',
            );
        }
    }

    /**
     * Compound work opens a session; isolation and conditioning fill it in.
     * The exercise reads that straight off the pattern rather than storing a
     * second flag that could disagree with it.
     */
    public function testWhetherAMovementIsCompoundFollowsItsPattern(): void
    {
        self::assertTrue(self::exercise()->setPattern(MovementPattern::SQUAT)->isCompound());
        self::assertTrue(self::exercise()->setPattern(MovementPattern::HINGE)->isCompound());
        self::assertFalse(self::exercise()->setPattern(MovementPattern::ISOLATION)->isCompound());
        self::assertFalse(self::exercise()->setPattern(MovementPattern::CONDITIONING)->isCompound());
    }

    public function testEveryPatternAnswersTheCompoundQuestionTheSameWayTheEnumDoes(): void
    {
        foreach (MovementPattern::cases() as $pattern) {
            self::assertSame(
                $pattern->isCompound(),
                self::exercise()->setPattern($pattern)->isCompound(),
                $pattern->value.' must not disagree with its own enum.',
            );
        }
    }

    public function testSecondaryMusclesAreStoredAsTheValuesTheSelectionQueryMatchesOn(): void
    {
        $exercise = self::exercise()
            ->setPrimaryMuscle(MuscleGroup::BACK)
            ->setSecondaryMuscles([MuscleGroup::BICEPS->value, MuscleGroup::CORE->value]);

        self::assertSame(MuscleGroup::BACK, $exercise->getPrimaryMuscle());
        self::assertSame(['biceps', 'core'], $exercise->getSecondaryMuscles());

        // The primary group is not repeated in the secondary list.
        self::assertNotContains(MuscleGroup::BACK->value, $exercise->getSecondaryMuscles());
    }

    public function testAMovementPrintsAsItsNameInTheDefaultLocale(): void
    {
        $exercise = new Exercise();
        $exercise->setName(new TranslatedString(['lv' => 'Pietupiens', 'ru' => 'Присед']));

        // No English, so Latvian is what prints.
        self::assertSame('Pietupiens', (string) $exercise);
    }

    public function testTheSettersChainAndStoreWhatTheyWereGiven(): void
    {
        $exercise = new Exercise();
        $name = TranslatedString::of('Back squat');
        $instructions = TranslatedString::of('Brace, then sit down between the hips.');

        $returned = $exercise
            ->setSlug('back-squat')
            ->setName($name)
            ->setInstructions($instructions)
            ->setPrimaryMuscle(MuscleGroup::QUADS)
            ->setSecondaryMuscles(['glutes'])
            ->setPattern(MovementPattern::SQUAT)
            ->setEquipment(EquipmentType::BARBELL)
            ->setContraindications(['knee'])
            ->setDifficulty(3)
            ->setActive(false);

        self::assertSame($exercise, $returned);
        self::assertSame('back-squat', $exercise->getSlug());
        self::assertSame($name, $exercise->getName());
        self::assertSame($instructions, $exercise->getInstructions());
        self::assertSame(MuscleGroup::QUADS, $exercise->getPrimaryMuscle());
        self::assertSame(['glutes'], $exercise->getSecondaryMuscles());
        self::assertSame(MovementPattern::SQUAT, $exercise->getPattern());
        self::assertSame(EquipmentType::BARBELL, $exercise->getEquipment());
        self::assertSame(['knee'], $exercise->getContraindications());
        self::assertSame(3, $exercise->getDifficulty());
        self::assertFalse($exercise->isActive());
    }

    private static function exercise(): Exercise
    {
        $exercise = (new Exercise())->setSlug('deadlift');
        $exercise->setName(TranslatedString::of('Deadlift'));

        return $exercise;
    }
}
