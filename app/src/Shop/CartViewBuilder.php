<?php

declare(strict_types=1);

namespace App\Shop;

use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Repository\ProductRepository;
use App\Repository\ProductVariantRepository;

/**
 * Turns the ids in a session into priced lines.
 *
 * This is the only place a cart is priced, and it prices against the database
 * every single time. A line whose product has since been deleted or switched
 * off disappears rather than being sold; a quantity above what is left on the
 * shelf is clamped down to it. Both corrections are written back to the
 * session, so the header count and the page agree.
 */
final class CartViewBuilder
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductVariantRepository $variants,
    ) {
    }

    public function build(Cart $cart): CartView
    {
        $stored = $cart->all();

        if ([] === $stored) {
            return new CartView([]);
        }

        $productIds = [];
        $variantIds = [];

        foreach (array_keys($stored) as $key) {
            $parsed = Cart::parseKey($key);

            if (null === $parsed) {
                continue;
            }

            $productIds[] = $parsed['product'];

            if (null !== $parsed['variant']) {
                $variantIds[] = $parsed['variant'];
            }
        }

        $productsById = $this->products->findByIdsIndexed(array_values(array_unique($productIds)));
        $variantsById = $this->variants->findByIdsIndexed(array_values(array_unique($variantIds)));

        $lines = [];
        $adjusted = false;

        foreach ($stored as $key => $quantity) {
            $parsed = Cart::parseKey($key);

            if (null === $parsed) {
                $cart->removeKey($key);
                $adjusted = true;

                continue;
            }

            $product = $productsById[$parsed['product']] ?? null;
            $variant = null === $parsed['variant'] ? null : ($variantsById[$parsed['variant']] ?? null);

            if (!$this->isStillSellable($product, $variant, $parsed['variant'])) {
                $cart->removeKey($key);
                $adjusted = true;

                continue;
            }

            // isStillSellable() has already refused a missing product.
            if (null === $product) {
                continue;
            }

            $available = Cart::availableStock($product, $variant);
            $wanted = min($quantity, Cart::MAX_QUANTITY);
            $allowed = min($wanted, $available);

            if ($allowed < 1) {
                $cart->removeKey($key);
                $adjusted = true;

                continue;
            }

            if ($allowed !== $quantity) {
                $cart->setQuantityForKey($key, $allowed, $available);
                $adjusted = true;
            }

            $lines[] = new CartLine(
                $key,
                $product,
                $variant,
                null === $variant ? $product->getPriceCents() : $variant->getPriceCents(),
                $allowed,
                $available,
            );
        }

        return new CartView($lines, $adjusted);
    }

    /**
     * A line survives only while everything it points at is still on sale. The
     * variant id is passed separately so a session pointing at a variant that
     * no longer exists is dropped rather than quietly falling back to the
     * product's own price.
     */
    private function isStillSellable(?Product $product, ?ProductVariant $variant, ?int $wantedVariantId): bool
    {
        if (null === $product || !$product->isActive()) {
            return false;
        }

        if (null === $wantedVariantId) {
            // A product that has grown variants can no longer be bought bare.
            return !$product->hasVariants();
        }

        if (null === $variant || !$variant->isActive()) {
            return false;
        }

        return $variant->getProduct()->getId() === $product->getId();
    }
}
