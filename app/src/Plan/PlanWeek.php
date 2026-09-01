<?php

declare(strict_types=1);

namespace App\Plan;

/**
 * One week of the mesocycle. Mirrors PlanWeek in ai-service/app/schemas.py.
 *
 * The deload flag matters more than it looks: a lighter week that is not
 * labelled as one reads to a member as the programme having lost its nerve,
 * and they train through it anyway.
 */
final readonly class PlanWeek
{
    /**
     * @param list<PlanDay> $days
     */
    public function __construct(
        public int $index,
        public bool $deload,
        public array $days,
    ) {
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            PayloadReader::int($payload['index'] ?? null),
            PayloadReader::bool($payload['deload'] ?? null),
            array_map(
                static fn (array $row): PlanDay => PlanDay::fromPayload($row),
                PayloadReader::rows($payload, 'days'),
            ),
        );
    }
}
