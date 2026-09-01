<?php

declare(strict_types=1);

namespace App\Shop;

/**
 * The basket as it is right now, priced against the live catalogue.
 *
 * "Adjusted" says whether hydrating it had to change anything - a discontinued
 * product dropped, a quantity clamped to the stock left. The cart page uses it
 * to tell the member why the numbers moved, instead of silently charging them
 * for something else.
 */
final readonly class CartView
{
    /**
     * @param list<CartLine> $lines
     */
    public function __construct(
        public array $lines,
        public bool $adjusted = false,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->lines;
    }

    public function getTotalCents(): int
    {
        $total = 0;

        foreach ($this->lines as $line) {
            $total += $line->getLineTotalCents();
        }

        return $total;
    }

    public function getCount(): int
    {
        $count = 0;

        foreach ($this->lines as $line) {
            $count += $line->quantity;
        }

        return $count;
    }
}
