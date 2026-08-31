<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/**
 * A branch's week of opening hours, read out of the JSON column on
 * {@see \App\Entity\Branch}.
 *
 * The entity stores the raw array because that is what the back office edits;
 * every question the public site actually asks of it - is this branch open
 * now, and how do I print the week without seven near-identical lines - lives
 * here instead, where it can be tested without a database.
 */
final readonly class OpeningSchedule
{
    /** @var array<int, OpeningPeriod> keyed by ISO-8601 weekday, 1 = Monday */
    private array $periods;

    /**
     * @param array<int, OpeningPeriod> $periods
     */
    private function __construct(array $periods)
    {
        ksort($periods);

        $this->periods = $periods;
    }

    /**
     * @param array<int|string, array{open?: string, close?: string}> $raw
     */
    public static function fromArray(array $raw): self
    {
        $periods = [];

        foreach ($raw as $day => $hours) {
            $day = (int) $day;
            $open = trim((string) ($hours['open'] ?? ''));
            $close = trim((string) ($hours['close'] ?? ''));

            if ($day < 1 || $day > 7 || '' === $open || '' === $close) {
                continue;
            }

            $periods[$day] = new OpeningPeriod($open, $close);
        }

        return new self($periods);
    }

    public function isEmpty(): bool
    {
        return [] === $this->periods;
    }

    public function forDay(int $isoWeekday): ?OpeningPeriod
    {
        return $this->periods[$isoWeekday] ?? null;
    }

    public function isOpenAt(DateTimeImmutable $moment): bool
    {
        return $this->forDay((int) $moment->format('N'))?->contains($moment->format('H:i')) ?? false;
    }

    /**
     * Consecutive days sharing the same hours, collapsed into one run each, so
     * a template can print "Mon-Thu 06:00-23:00" instead of four rows.
     *
     * @return list<OpeningRun>
     */
    public function grouped(): array
    {
        $runs = [];

        foreach ($this->periods as $day => $period) {
            // Popping and pushing rather than rewriting the last key keeps this
            // a list, which is what the return type promises.
            $previous = array_pop($runs);

            if (null === $previous) {
                $runs[] = new OpeningRun($day, $day, $period);

                continue;
            }

            if ($previous->lastDay === $day - 1 && $previous->period->equals($period)) {
                $runs[] = new OpeningRun($previous->firstDay, $day, $period);

                continue;
            }

            $runs[] = $previous;
            $runs[] = new OpeningRun($day, $day, $period);
        }

        return $runs;
    }
}
