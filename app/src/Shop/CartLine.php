<?php

declare(strict_types=1);

namespace App\Shop;

use App\Domain\TranslatedString;
use App\Entity\Product;
use App\Entity\ProductVariant;

/**
 * One priced basket line, built fresh on every render.
 *
 * Everything here except the quantity comes from the database, so a price
 * change between adding and paying is reflected immediately rather than being
 * remembered from whatever the session last saw.
 */
final readonly class CartLine
{
    public function __construct(
        public string $key,
        public Product $product,
        public ?ProductVariant $variant,
        public int $unitPriceCents,
        public int $quantity,
        public int $availableStock,
    ) {
    }

    public function getLineTotalCents(): int
    {
        return $this->unitPriceCents * $this->quantity;
    }

    /**
     * The product name with the variant label appended, which is also what
     * gets snapshotted onto the order line.
     */
    public function getName(): TranslatedString
    {
        if (null === $this->variant) {
            return $this->product->getName();
        }

        $values = [];

        foreach (TranslatedString::LOCALES as $locale) {
            $label = $this->variant->getLabel()->get($locale);
            $name = $this->product->getName()->get($locale);

            $values[$locale] = '' === $label ? $name : $name.' · '.$label;
        }

        return new TranslatedString($values);
    }

    public function getSku(): string
    {
        return null === $this->variant ? $this->product->getSku() : $this->variant->getSku();
    }
}
