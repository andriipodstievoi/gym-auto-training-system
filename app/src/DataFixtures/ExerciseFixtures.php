<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Domain\Enum\EquipmentType;
use App\Domain\Enum\Limitation;
use App\Domain\Enum\MovementPattern;
use App\Domain\Enum\MuscleGroup;
use App\Domain\TranslatedString;
use App\Entity\Exercise;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * The exercise library the M6 rule engine selects from.
 *
 * Coverage is deliberate rather than exhaustive: every movement pattern has at
 * least one barbell, one dumbbell and one bodyweight or machine option, so a
 * member training at home can still be given a balanced programme.
 *
 * Names carry all three locales because they appear inside generated plans.
 * Coaching instructions are English-only for now and fall back automatically;
 * the back office is where the Latvian and Russian versions get filled in.
 */
final class ExerciseFixtures extends Fixture
{
    /**
     * slug, [en, lv, ru], primary muscle, secondary muscles, pattern,
     * equipment, contraindications, difficulty, english coaching cue.
     */
    private const array EXERCISES = [
        // --- Squat pattern -------------------------------------------------
        ['barbell-back-squat', ['Barbell back squat', 'Pietupiens ar stieni', 'Присед со штангой'],
            MuscleGroup::QUADS, [MuscleGroup::GLUTES, MuscleGroup::HAMSTRINGS, MuscleGroup::CORE],
            MovementPattern::SQUAT, EquipmentType::BARBELL, [Limitation::KNEE, Limitation::LOWER_BACK], 3,
            'Brace before you unrack, sit between your hips, and drive the floor away.'],
        ['goblet-squat', ['Goblet squat', 'Pietupiens ar hanteli pie krūtīm', 'Гоблет-присед'],
            MuscleGroup::QUADS, [MuscleGroup::GLUTES, MuscleGroup::CORE],
            MovementPattern::SQUAT, EquipmentType::DUMBBELL, [Limitation::KNEE], 1,
            'Hold the bell against your chest and let your elbows track inside your knees.'],
        ['leg-press', ['Leg press', 'Kāju prese', 'Жим ногами'],
            MuscleGroup::QUADS, [MuscleGroup::GLUTES],
            MovementPattern::SQUAT, EquipmentType::MACHINE, [Limitation::KNEE], 1,
            'Stop before your lower back rounds off the pad.'],

        // --- Hinge pattern -------------------------------------------------
        ['conventional-deadlift', ['Deadlift', 'Vilkšana no grīdas', 'Становая тяга'],
            MuscleGroup::BACK, [MuscleGroup::HAMSTRINGS, MuscleGroup::GLUTES],
            MovementPattern::HINGE, EquipmentType::BARBELL, [Limitation::LOWER_BACK], 3,
            'Pull the slack out of the bar before anything moves.'],
        ['romanian-deadlift', ['Romanian deadlift', 'Rumāņu vilkšana', 'Румынская тяга'],
            MuscleGroup::HAMSTRINGS, [MuscleGroup::GLUTES, MuscleGroup::BACK],
            MovementPattern::HINGE, EquipmentType::BARBELL, [Limitation::LOWER_BACK], 2,
            'Push your hips back, keep the bar against your legs, stop when your hamstrings run out.'],
        ['hip-thrust', ['Hip thrust', 'Gurnu pacelšana', 'Ягодичный мост'],
            MuscleGroup::GLUTES, [MuscleGroup::HAMSTRINGS],
            MovementPattern::HINGE, EquipmentType::BARBELL, [], 2,
            'Finish with your ribs down, not with your lower back arched.'],
        ['kettlebell-swing', ['Kettlebell swing', 'Svaru bumbas šūpošana', 'Мах гирей'],
            MuscleGroup::GLUTES, [MuscleGroup::HAMSTRINGS, MuscleGroup::CORE],
            MovementPattern::HINGE, EquipmentType::KETTLEBELL, [Limitation::LOWER_BACK], 2,
            'The bell is thrown by your hips, not lifted by your arms.'],

        // --- Lunge pattern -------------------------------------------------
        ['walking-lunge', ['Walking lunge', 'Izklupieni ejot', 'Выпады в движении'],
            MuscleGroup::QUADS, [MuscleGroup::GLUTES, MuscleGroup::CORE],
            MovementPattern::LUNGE, EquipmentType::DUMBBELL, [Limitation::KNEE], 2,
            'Step long enough that your front shin stays close to vertical.'],
        ['bulgarian-split-squat', ['Bulgarian split squat', 'Bulgāru izklupiens', 'Болгарский присед'],
            MuscleGroup::QUADS, [MuscleGroup::GLUTES],
            MovementPattern::LUNGE, EquipmentType::DUMBBELL, [Limitation::KNEE], 2,
            'Most of the work belongs to the front leg; the back foot only balances.'],

        // --- Horizontal push -----------------------------------------------
        ['barbell-bench-press', ['Barbell bench press', 'Spiešana guļus ar stieni', 'Жим лёжа со штангой'],
            MuscleGroup::CHEST, [MuscleGroup::TRICEPS, MuscleGroup::SHOULDERS],
            MovementPattern::HORIZONTAL_PUSH, EquipmentType::BARBELL, [Limitation::SHOULDER], 2,
            'Keep your shoulder blades pinned to the bench for the whole set.'],
        ['dumbbell-bench-press', ['Dumbbell bench press', 'Spiešana guļus ar hantelēm', 'Жим гантелей лёжа'],
            MuscleGroup::CHEST, [MuscleGroup::TRICEPS, MuscleGroup::SHOULDERS],
            MovementPattern::HORIZONTAL_PUSH, EquipmentType::DUMBBELL, [Limitation::SHOULDER], 1,
            'Lower until your upper arms are level with your torso, no deeper.'],
        ['chest-press-machine', ['Chest press machine', 'Krūšu prese trenažierī', 'Жим от груди в тренажёре'],
            MuscleGroup::CHEST, [MuscleGroup::TRICEPS],
            MovementPattern::HORIZONTAL_PUSH, EquipmentType::MACHINE, [], 1,
            'Set the seat so the handles start level with the middle of your chest.'],
        ['push-up', ['Push-up', 'Atspiešanās', 'Отжимание'],
            MuscleGroup::CHEST, [MuscleGroup::TRICEPS, MuscleGroup::CORE],
            MovementPattern::HORIZONTAL_PUSH, EquipmentType::BODYWEIGHT, [], 1,
            'Squeeze your glutes so your hips do not sag halfway through the set.'],

        // --- Vertical push -------------------------------------------------
        ['overhead-press', ['Overhead press', 'Spiešana virs galvas', 'Жим стоя'],
            MuscleGroup::SHOULDERS, [MuscleGroup::TRICEPS, MuscleGroup::CORE],
            MovementPattern::VERTICAL_PUSH, EquipmentType::BARBELL, [Limitation::SHOULDER, Limitation::NECK], 3,
            'Move your head back out of the way, then push it through once the bar passes.'],
        ['dumbbell-shoulder-press', ['Dumbbell shoulder press', 'Plecu spiešana ar hantelēm', 'Жим гантелей над головой'],
            MuscleGroup::SHOULDERS, [MuscleGroup::TRICEPS],
            MovementPattern::VERTICAL_PUSH, EquipmentType::DUMBBELL, [Limitation::SHOULDER], 2,
            'A slightly angled path is kinder to the shoulder than a strict vertical one.'],

        // --- Vertical pull -------------------------------------------------
        ['pull-up', ['Pull-up', 'Pievilkšanās', 'Подтягивание'],
            MuscleGroup::BACK, [MuscleGroup::BICEPS],
            MovementPattern::VERTICAL_PULL, EquipmentType::BODYWEIGHT, [Limitation::SHOULDER, Limitation::ELBOW], 3,
            'Start each rep from a full hang with your shoulders active.'],
        ['lat-pulldown', ['Lat pulldown', 'Augšējā bloka vilkšana', 'Верхняя тяга'],
            MuscleGroup::BACK, [MuscleGroup::BICEPS],
            MovementPattern::VERTICAL_PULL, EquipmentType::CABLE, [], 1,
            'Pull your elbows down and back, not the bar down to your chin.'],

        // --- Horizontal pull -----------------------------------------------
        ['barbell-row', ['Barbell row', 'Vilkšana pie vēdera ar stieni', 'Тяга штанги в наклоне'],
            MuscleGroup::BACK, [MuscleGroup::BICEPS],
            MovementPattern::HORIZONTAL_PULL, EquipmentType::BARBELL, [Limitation::LOWER_BACK], 2,
            'Hold your torso angle still; if it rises to move the bar, the weight is too heavy.'],
        ['seated-cable-row', ['Seated cable row', 'Sēdus vilkšana pie bloka', 'Тяга блока сидя'],
            MuscleGroup::BACK, [MuscleGroup::BICEPS],
            MovementPattern::HORIZONTAL_PULL, EquipmentType::CABLE, [], 1,
            'Let the weight stretch your back at the front of each rep.'],
        ['dumbbell-row', ['Dumbbell row', 'Vilkšana ar hanteli', 'Тяга гантели'],
            MuscleGroup::BACK, [MuscleGroup::BICEPS],
            MovementPattern::HORIZONTAL_PULL, EquipmentType::DUMBBELL, [], 1,
            'Row towards your hip rather than straight up to your shoulder.'],

        // --- Isolation -----------------------------------------------------
        ['face-pull', ['Face pull', 'Vilkšana pie sejas', 'Тяга к лицу'],
            MuscleGroup::SHOULDERS, [MuscleGroup::BACK],
            MovementPattern::ISOLATION, EquipmentType::CABLE, [], 1,
            'High elbows, and pull the rope apart as it reaches your face.'],
        ['band-pull-apart', ['Band pull-apart', 'Gumijas atvilkšana', 'Разведение с резиной'],
            MuscleGroup::SHOULDERS, [MuscleGroup::BACK],
            MovementPattern::ISOLATION, EquipmentType::BAND, [], 1,
            'A good warm-up set before any pressing day.'],
        ['dumbbell-curl', ['Dumbbell curl', 'Bicepsa locīšana ar hantelēm', 'Подъём гантелей на бицепс'],
            MuscleGroup::BICEPS, [],
            MovementPattern::ISOLATION, EquipmentType::DUMBBELL, [Limitation::ELBOW], 1,
            'Keep your elbow still and under your shoulder.'],
        ['cable-triceps-pushdown', ['Triceps pushdown', 'Tricepsa spiešana pie bloka', 'Разгибание на трицепс'],
            MuscleGroup::TRICEPS, [],
            MovementPattern::ISOLATION, EquipmentType::CABLE, [Limitation::ELBOW], 1,
            'Elbows pinned to your sides, full lockout at the bottom.'],
        ['leg-curl', ['Leg curl', 'Kāju locīšana', 'Сгибание ног'],
            MuscleGroup::HAMSTRINGS, [],
            MovementPattern::ISOLATION, EquipmentType::MACHINE, [], 1,
            'Control the lowering; that is where the hamstring work happens.'],
        ['standing-calf-raise', ['Standing calf raise', 'Ikru pacelšana stāvus', 'Подъём на носки стоя'],
            MuscleGroup::CALVES, [],
            MovementPattern::ISOLATION, EquipmentType::MACHINE, [], 1,
            'Pause at the bottom to kill the bounce.'],
        ['plank', ['Plank', 'Planka', 'Планка'],
            MuscleGroup::CORE, [],
            MovementPattern::ISOLATION, EquipmentType::BODYWEIGHT, [], 1,
            'Ribs down, glutes tight; time it rather than chasing minutes.'],
        ['hanging-knee-raise', ['Hanging knee raise', 'Ceļu pacelšana karājoties', 'Подъём коленей в висе'],
            MuscleGroup::CORE, [],
            MovementPattern::ISOLATION, EquipmentType::BODYWEIGHT, [Limitation::SHOULDER], 2,
            'Curl your pelvis up rather than just swinging your legs.'],

        // --- Carry and conditioning ----------------------------------------
        ['farmer-carry', ['Farmer carry', 'Fermera pastaiga', 'Прогулка фермера'],
            MuscleGroup::CORE, [MuscleGroup::BACK],
            MovementPattern::CARRY, EquipmentType::DUMBBELL, [], 1,
            'Walk tall and quiet; distance beats weight to begin with.'],
        ['rowing-intervals', ['Rowing intervals', 'Airēšanas intervāli', 'Интервалы на гребле'],
            MuscleGroup::BACK, [MuscleGroup::QUADS, MuscleGroup::CORE],
            MovementPattern::CONDITIONING, EquipmentType::CARDIO, [Limitation::LOWER_BACK], 1,
            'Legs, then body, then arms - and the reverse on the way back.'],
        ['incline-treadmill-walk', ['Incline treadmill walk', 'Iešana slīpumā', 'Ходьба в горку'],
            MuscleGroup::CALVES, [MuscleGroup::GLUTES],
            MovementPattern::CONDITIONING, EquipmentType::CARDIO, [], 1,
            'Steady pace you could hold a conversation at, hands off the rails.'],
    ];

    public function load(ObjectManager $manager): void
    {
        foreach (self::EXERCISES as [$slug, $names, $primary, $secondary, $pattern, $equipment, $limitations, $difficulty, $cue]) {
            $manager->persist(
                (new Exercise())
                    ->setSlug($slug)
                    ->setName(TranslatedString::of(...$names))
                    ->setInstructions(TranslatedString::of($cue))
                    ->setPrimaryMuscle($primary)
                    ->setSecondaryMuscles(array_map(
                        static fn (MuscleGroup $muscle): string => $muscle->value,
                        $secondary,
                    ))
                    ->setPattern($pattern)
                    ->setEquipment($equipment)
                    ->setContraindications(array_map(
                        static fn (Limitation $limitation): string => $limitation->value,
                        $limitations,
                    ))
                    ->setDifficulty($difficulty)
            );
        }

        $manager->flush();
    }
}
