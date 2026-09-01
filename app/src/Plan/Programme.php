<?php

declare(strict_types=1);

namespace App\Plan;

/**
 * A stored plan payload, read as objects instead of nested arrays.
 *
 * The payload is kept whole rather than shredded into tables - the engine owns
 * that shape and versions it with engine_version, so normalising it here would
 * buy a schema migration every time the engine learns a new field. The cost of
 * that decision is exactly this class: something has to turn the blob back
 * into things a template can ask questions of, and it should be one place
 * rather than every template indexing arrays by string.
 */
final readonly class Programme
{
    /**
     * @param list<PlanWeek> $weeks
     */
    public function __construct(
        public string $split,
        public array $weeks,
        public string $coachingNotes,
    ) {
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            PayloadReader::string($payload['split'] ?? null),
            array_map(
                static fn (array $row): PlanWeek => PlanWeek::fromPayload($row),
                PayloadReader::rows($payload, 'weeks'),
            ),
            PayloadReader::string($payload['coaching_notes'] ?? null),
        );
    }

    public function getWeekCount(): int
    {
        return count($this->weeks);
    }

    /**
     * How many sessions a week this programme actually prescribes, read from
     * the plan rather than from the assessment. They agree today; if the engine
     * ever has to drop a day the page should say what was written, not what
     * was asked for.
     */
    public function getDaysPerWeek(): int
    {
        $days = 0;

        foreach ($this->weeks as $week) {
            $days = max($days, count($week->days));
        }

        return $days;
    }
}
