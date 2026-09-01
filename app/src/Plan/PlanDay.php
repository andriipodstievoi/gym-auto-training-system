<?php

declare(strict_types=1);

namespace App\Plan;

/**
 * One training session inside a week. Mirrors PlanDay in
 * ai-service/app/schemas.py.
 *
 * The label is the engine's own name for the session - "Upper A", "Push",
 * "Full body 1" - and is deliberately not translated: it is generated in the
 * locale the member answered the questionnaire in.
 */
final readonly class PlanDay
{
    /**
     * @param list<PlanExercise> $exercises
     */
    public function __construct(
        public int $index,
        public string $label,
        public array $exercises,
    ) {
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            PayloadReader::int($payload['index'] ?? null),
            PayloadReader::string($payload['label'] ?? null),
            array_map(
                static fn (array $row): PlanExercise => PlanExercise::fromPayload($row),
                PayloadReader::rows($payload, 'exercises'),
            ),
        );
    }
}
