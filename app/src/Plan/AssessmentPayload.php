<?php

declare(strict_types=1);

namespace App\Plan;

use App\Domain\Enum\Limitation;
use App\Entity\Assessment;

/**
 * An Assessment in the exact shape Pydantic's Assessment model expects.
 *
 * This is the whole contract in one function, which is why it is a class of
 * its own rather than a private method on PlanService: every key here is a
 * field name in ai-service/app/schemas.py, a wrong one is a 422 the member
 * cannot act on, and it needs to be assertable without a network call.
 *
 * The snake_case is not a style choice. profile.height_cm, days_per_week,
 * minutes_per_session, disliked_exercises and every par_q key are named by the
 * Python side; PHP's camelCase stops at this boundary.
 *
 * @see \App\Tests\Plan\AssessmentPayloadTest which asserts the shape field by
 *      field against the Pydantic model
 */
final class AssessmentPayload
{
    /**
     * @return array{
     *     profile: array{age: int, height_cm: int, weight_kg: float},
     *     goal: string,
     *     experience: string,
     *     days_per_week: int,
     *     minutes_per_session: int,
     *     equipment: string,
     *     limitations: list<string>,
     *     disliked_exercises: list<string>,
     *     par_q: array{
     *         heart_condition: bool,
     *         chest_pain: bool,
     *         dizziness_or_fainting: bool,
     *         bone_or_joint_problem: bool,
     *         blood_pressure_medication: bool,
     *         recent_surgery: bool,
     *         pregnancy: bool,
     *         other_reason_not_to_exercise: bool,
     *     },
     *     locale: string,
     * }
     */
    public static function fromAssessment(Assessment $assessment): array
    {
        return [
            'profile' => [
                'age' => $assessment->getAge(),
                'height_cm' => $assessment->getHeightCm(),
                'weight_kg' => $assessment->getWeightKg(),
            ],
            'goal' => $assessment->getGoal()->value,
            'experience' => $assessment->getExperience()->value,
            'days_per_week' => $assessment->getDaysPerWeek(),
            'minutes_per_session' => $assessment->getMinutesPerSession(),
            'equipment' => $assessment->getEquipment()->value,
            // Sent as backing values, and only the ones the enum still knows:
            // Pydantic rejects the whole assessment over one unknown member.
            'limitations' => array_map(
                static fn (Limitation $limitation): string => $limitation->value,
                $assessment->getLimitationEnums(),
            ),
            'disliked_exercises' => $assessment->getDislikedExercises(),
            'par_q' => $assessment->getParQ(),
            'locale' => $assessment->getLocale(),
        ];
    }
}
