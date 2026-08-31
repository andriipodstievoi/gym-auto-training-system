<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One continuous stretch of opening, as two "HH:MM" wall-clock strings.
 */
final readonly class OpeningPeriod
{
    public function __construct(
        public string $open,
        public string $close,
    ) {
    }

    /**
     * @param string $time wall-clock "HH:MM"
     */
    public function contains(string $time): bool
    {
        // Zero-padded HH:MM sorts chronologically, so string comparison is enough.
        if ($this->close > $this->open) {
            return $time >= $this->open && $time < $this->close;
        }

        // A period that closes at or before it opens runs past midnight.
        return $time >= $this->open || $time < $this->close;
    }

    public function equals(self $other): bool
    {
        return $this->open === $other->open && $this->close === $other->close;
    }
}
