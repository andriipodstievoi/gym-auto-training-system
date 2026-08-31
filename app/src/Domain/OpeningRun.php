<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * A block of consecutive weekdays that all open at the same times.
 */
final readonly class OpeningRun
{
    public function __construct(
        public int $firstDay,
        public int $lastDay,
        public OpeningPeriod $period,
    ) {
    }

    public function isSingleDay(): bool
    {
        return $this->firstDay === $this->lastDay;
    }
}
