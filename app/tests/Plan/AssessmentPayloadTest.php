<?php

declare(strict_types=1);

namespace App\Tests\Plan;

use App\Domain\Enum\Equipment;
use App\Domain\Enum\Experience;
use App\Domain\Enum\Goal;
use App\Domain\Enum\Limitation;
use App\Entity\Assessment;
use App\Entity\User;
use App\Plan\AssessmentPayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What actually goes on the wire.
 *
 * Two assertions, for two different mistakes. The first pins the exact array,
 * key by key and value by value: it is the only place the snake_case wire
 * names are written down next to the camelCase getters they come from, and a
 * typo in one of them is a 422 from a service the member cannot see, which
 * reads to them as the site being broken.
 *
 * The second reads the field names straight out of ai-service/app/schemas.py,
 * so a field renamed on the Python side fails here rather than in production.
 * PythonContractTest does the same for the enums; this does it for the shape.
 */
final class AssessmentPayloadTest extends TestCase
{
    private const string SCHEMAS = __DIR__.'/../../../ai-service/app/schemas.py';

    public function testTheWireShapeIsExactlyWhatPydanticExpects(): void
    {
        self::assertSame(
            [
                'profile' => [
                    'age' => 34,
                    'height_cm' => 182,
                    'weight_kg' => 84.5,
                ],
                'goal' => 'muscle_gain',
                'experience' => 'intermediate',
                'days_per_week' => 4,
                'minutes_per_session' => 60,
                'equipment' => 'full_gym',
                'limitations' => ['lower_back', 'knee'],
                'disliked_exercises' => ['Burpees', 'Box jumps'],
                'par_q' => [
                    'heart_condition' => false,
                    'chest_pain' => false,
                    'dizziness_or_fainting' => true,
                    'bone_or_joint_problem' => false,
                    'blood_pressure_medication' => false,
                    'recent_surgery' => false,
                    'pregnancy' => false,
                    'other_reason_not_to_exercise' => false,
                ],
                'locale' => 'ru',
            ],
            AssessmentPayload::fromAssessment(self::assessment()),
        );
    }

    /**
     * A limitation the enum no longer knows is dropped rather than sent on: an
     * assessment stored years ago must still produce a PDF, and Pydantic
     * rejects the whole thing over one unknown member.
     */
    public function testARetiredLimitationDoesNotTravel(): void
    {
        $assessment = self::assessment()->setLimitations(['knee', 'tail', 'shoulder']);

        self::assertSame(
            ['knee', 'shoulder'],
            AssessmentPayload::fromAssessment($assessment)['limitations'],
        );
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function modelProvider(): iterable
    {
        yield 'Assessment' => ['Assessment', [
            'profile', 'goal', 'experience', 'days_per_week', 'minutes_per_session',
            'equipment', 'limitations', 'disliked_exercises', 'par_q', 'locale',
        ]];
        yield 'Profile' => ['Profile', ['age', 'height_cm', 'weight_kg']];
        yield 'ParQ' => ['ParQ', Assessment::PAR_Q_FIELDS];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('modelProvider')]
    public function testTheKeysSentAreTheFieldsPythonDeclares(string $model, array $expected): void
    {
        self::assertSame(
            self::pythonFields($model),
            $expected,
            sprintf(
                'App\Plan\AssessmentPayload and %s in ai-service/app/schemas.py have drifted apart. '
                .'A key this side invents is a field Pydantic ignores; a key it stops sending is a 422.',
                $model,
            ),
        );
    }

    /**
     * The payload's own keys, so the two halves of this test cannot pass while
     * disagreeing with each other.
     */
    public function testTheProviderDescribesThePayloadThisClassActuallyBuilds(): void
    {
        $payload = AssessmentPayload::fromAssessment(self::assessment());
        $models = iterator_to_array(self::modelProvider());

        self::assertSame($models['Assessment'][1], array_keys($payload));
        self::assertSame($models['Profile'][1], array_keys($payload['profile']));
        self::assertSame($models['ParQ'][1], array_keys($payload['par_q']));
    }

    private static function assessment(): Assessment
    {
        $user = (new User())->setEmail('member@speks.lv')->setLocale('lv');

        return (new Assessment($user))
            ->setAge(34)
            ->setHeightCm(182)
            ->setWeightKg(84.5)
            ->setGoal(Goal::MUSCLE_GAIN)
            ->setExperience(Experience::INTERMEDIATE)
            ->setDaysPerWeek(4)
            ->setMinutesPerSession(60)
            ->setEquipment(Equipment::FULL_GYM)
            ->setLimitations([Limitation::LOWER_BACK->value, Limitation::KNEE->value])
            ->setDislikedExercises(['Burpees', 'Box jumps'])
            ->setDizzinessOrFainting(true)
            // Deliberately not the account's language: the questionnaire
            // records the language it was answered in.
            ->setLocale('ru');
    }

    /**
     * The declared fields of one Pydantic model, in declaration order.
     *
     * @return list<string>
     */
    private static function pythonFields(string $model): array
    {
        $source = file_get_contents(self::SCHEMAS);

        if (false === $source) {
            throw new RuntimeException(sprintf('Could not read %s.', self::SCHEMAS));
        }

        // The class body runs to the first line that is neither indented nor
        // blank - the same reading PythonContractTest does of the enums.
        $pattern = sprintf('/^class %s\(BaseModel\):\R((?:[ \t]+.*\R|\R)*)/m', preg_quote($model, '/'));

        if (1 !== preg_match($pattern, $source, $matches)) {
            throw new RuntimeException(sprintf('No BaseModel called "%s" in %s.', $model, self::SCHEMAS));
        }

        // An annotated attribute at exactly one level of indentation. Methods
        // and docstrings do not match, because neither is "name: type".
        if (1 > preg_match_all('/^ {4}([a-z_][a-z0-9_]*)\s*:\s*[^\s=]/m', $matches[1], $fields)) {
            throw new RuntimeException(sprintf('BaseModel "%s" declares no fields.', $model));
        }

        return $fields[1];
    }

    public function testTheSchemasFileIsWhereThisTestThinksItIs(): void
    {
        self::assertFileExists(self::SCHEMAS);
    }
}
