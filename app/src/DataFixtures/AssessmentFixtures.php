<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Domain\Enum\Equipment;
use App\Domain\Enum\Experience;
use App\Domain\Enum\Goal;
use App\Domain\Enum\Limitation;
use App\Entity\Assessment;
use App\Entity\TrainingPlan;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * One completed questionnaire and the programme it produced, so the account
 * page, the plan page and the PDF button all have something real on a fresh
 * database.
 *
 * The payload below is a transcript, not a second rule engine. It is what
 * ai-service 1.0.0 answered for exactly these answers, recorded once and
 * written out here in the shape the tables of an M1 fixture already use:
 * numbers per week rather than five copies of the same twenty exercises.
 * Nothing here computes a programme - if the engine changes its mind about
 * this member, this transcript is re-recorded, not recalculated.
 *
 * Fixtures must never call the plan service. CI loads them with nothing
 * listening on port 8001, and a fixture that needs a second runtime up is a
 * fixture that fails on somebody's first clone.
 */
final class AssessmentFixtures extends Fixture implements DependentFixtureInterface
{
    private const string ENGINE_VERSION = '1.0.0';

    private const string SPLIT = 'Upper / Lower';

    /**
     * Every session opens with the same warm-up, exactly as the engine writes
     * it: one block, no reps in reserve, timed rather than counted.
     */
    private const string WARM_UP_NOTES = 'Viegls kardio, locītavu vingrinājumi, tad iesildošās sērijas pirmajam vingrinājumam.';

    /**
     * Reps in reserve, week by week: the block gets closer to failure, then
     * backs a long way off for the deload.
     *
     * @var list<int>
     */
    private const array RIR_BY_WEEK = [2, 1, 0, 0, 5];

    private const int DELOAD_WEEK = 5;

    /**
     * The four sessions: label, then each movement as name, rep range and the
     * set count for each of the five weeks.
     *
     * @var list<array{string, list<array{string, string, list<int>}>}>
     */
    private const array DAYS = [
        ['Upper A', [
            ['Barbell Bench Press', '6-8', [4, 4, 5, 5, 2]],
            ['Seated Cable Row', '6-8', [4, 4, 5, 5, 2]],
            ['Overhead Press', '6-8', [4, 4, 5, 5, 2]],
            ['Lat Pulldown', '6-8', [4, 4, 5, 5, 2]],
        ]],
        ['Lower A', [
            ['Hack Squat Machine', '6-8', [4, 4, 5, 5, 2]],
            ['Barbell Hip Thrust', '6-8', [4, 4, 5, 5, 2]],
            ['Walking Lunge', '6-8', [4, 4, 5, 5, 2]],
            ['Standing Calf Raise', '10-12', [3, 3, 4, 4, 2]],
            // Last in and first to be trimmed: the session has a time budget,
            // and the engine spends what is left of it on one set.
            ['Cable Crunch', '10-12', [1, 1, 1, 1, 1]],
        ]],
        ['Upper B', [
            ['Neutral-Grip Pulldown', '6-8', [4, 4, 5, 5, 2]],
            ['Machine Shoulder Press', '6-8', [4, 4, 5, 5, 2]],
            ['Chest-Supported Machine Row', '6-8', [4, 4, 5, 5, 2]],
            ['Incline Barbell Bench Press', '6-8', [4, 4, 5, 5, 2]],
        ]],
        ['Lower B', [
            // No barbell squat or deadlift anywhere in the block: this member
            // declared a lower back, and the engine programmed around it.
            ['Banded Pull-Through', '6-8', [4, 4, 5, 5, 2]],
            ['Leg Press', '6-8', [4, 4, 5, 5, 2]],
            ['Cable Crunch', '10-12', [4, 4, 5, 5, 2]],
            ['Seated Calf Raise', '10-12', [3, 3, 4, 4, 2]],
            ['Seated Leg Curl', '10-12', [1, 1, 1, 1, 1]],
        ]],
    ];

    private const string COACHING_NOTES = 'Sadalījums: Upper / Lower. 4 treniņi nedēļā, katrs apmēram 60 minūtes, 5 nedēļu ciklā. '
        .'5. nedēļa ir atslodze: sēriju skaits ir samazināts un katra sērija beidzas tālāk no atteices, lai uzkrātais nogurums pazustu pirms nākamā cikla. '
        .'Atkārtojumu rezerve (RIR) ir tas, cik atkārtojumu tev vēl jāspēj izdarīt, kad noliec svaru. '
        .'Pievieno nedaudz svara vai vienu atkārtojumu, kad sasniedz diapazona augšējo robežu ar noteikto rezervi. '
        .'Iesildies 8 minūtes un pakāpeniski sasniedz pirmo darba sēriju. '
        .'Starp darba sērijām atpūties apmēram 2-3 minūtes.';

    /**
     * The engine's own wording, kept because the payload is stored whole. The
     * page shows the translated plan.disclaimer instead.
     */
    private const string DISCLAIMER = 'This programme is general fitness guidance, not medical advice. '
        .'Stop and consult a physician if you experience pain, dizziness or chest discomfort.';

    public function load(ObjectManager $manager): void
    {
        $answered = new DateTimeImmutable('-3 days');

        /** @var User $member */
        $member = $this->getReference(UserFixtures::REFERENCE_MEMBER, User::class);

        // The locale comes off the account, because that is the language this
        // member reads the site in and the language the notes below are in.
        $assessment = (new Assessment($member, $answered))
            ->setAge(34)
            ->setHeightCm(182)
            ->setWeightKg(84.5)
            ->setGoal(Goal::MUSCLE_GAIN)
            ->setExperience(Experience::INTERMEDIATE)
            ->setDaysPerWeek(4)
            ->setMinutesPerSession(60)
            ->setEquipment(Equipment::FULL_GYM)
            ->setLimitations([Limitation::LOWER_BACK->value])
            ->setDislikedExercises(['Burpees']);

        $manager->persist($assessment);
        $manager->persist(TrainingPlan::fromPayload($assessment, self::payload($answered), $answered));

        $manager->flush();
    }

    /**
     * The recorded response, rebuilt row by row.
     *
     * @return array<array-key, mixed>
     */
    private static function payload(DateTimeImmutable $generatedAt): array
    {
        $weeks = [];

        foreach (self::RIR_BY_WEEK as $index => $rir) {
            $weeks[] = [
                'index' => $index + 1,
                'deload' => self::DELOAD_WEEK === $index + 1,
                'days' => self::days($index, $rir),
            ];
        }

        return [
            'status' => 'ok',
            'generated_at' => $generatedAt->format('Y-m-d\TH:i:s\Z'),
            'engine_version' => self::ENGINE_VERSION,
            'llm_used' => false,
            'split' => self::SPLIT,
            'weeks' => $weeks,
            'coaching_notes' => self::COACHING_NOTES,
            'disclaimer' => self::DISCLAIMER,
        ];
    }

    /**
     * The four sessions of one week.
     *
     * @return list<array{index: int, label: string, exercises: list<array{name: string, sets: int, reps: string, rir: int|null, notes: string}>}>
     */
    private static function days(int $week, int $rir): array
    {
        $days = [];

        foreach (self::DAYS as $index => [$label, $movements]) {
            $exercises = [[
                'name' => 'Warm-up',
                'sets' => 1,
                'reps' => '8 min',
                'rir' => null,
                'notes' => self::WARM_UP_NOTES,
            ]];

            foreach ($movements as [$name, $reps, $sets]) {
                $exercises[] = [
                    'name' => $name,
                    'sets' => $sets[$week],
                    'reps' => $reps,
                    'rir' => $rir,
                    'notes' => '',
                ];
            }

            $days[] = [
                'index' => $index + 1,
                'label' => $label,
                'exercises' => $exercises,
            ];
        }

        return $days;
    }

    /**
     * @return list<class-string>
     */
    public function getDependencies(): array
    {
        return [UserFixtures::class];
    }
}
