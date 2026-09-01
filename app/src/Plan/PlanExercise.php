<?php

declare(strict_types=1);

namespace App\Plan;

/**
 * One prescribed movement, read out of a stored plan payload.
 *
 * Mirrors PlanExercise in ai-service/app/schemas.py. Nothing here is computed:
 * the engine decided every number, and this only names them so a template can
 * ask for a set count instead of indexing an array with a string.
 *
 * $reps is a range like "6-8", or "AMRAP", or "8 min" - never a single number.
 * $rir is reps in reserve, and is null for warm-ups and cardio, where the idea
 * does not apply.
 */
final readonly class PlanExercise
{
    public function __construct(
        public string $name,
        public int $sets,
        public string $reps,
        public ?int $rir,
        public string $notes,
    ) {
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            PayloadReader::string($payload['name'] ?? null),
            PayloadReader::int($payload['sets'] ?? null),
            PayloadReader::string($payload['reps'] ?? null),
            PayloadReader::nullableInt($payload['rir'] ?? null),
            PayloadReader::string($payload['notes'] ?? null),
        );
    }
}
