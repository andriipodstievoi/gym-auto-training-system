<?php

declare(strict_types=1);

namespace App\Shop;

use App\Entity\Product;
use App\Entity\ProductVariant;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * The basket, kept in the session so it survives signing in.
 *
 * It stores identifiers and quantities and nothing else. Prices are never
 * written here: a session is client-controlled state with a long life, and a
 * cart that remembered "29.90" would let a stale - or edited - session decide
 * what somebody pays. Every price on screen and every price sent to Stripe is
 * re-read from the database instead, by {@see CartViewBuilder}.
 */
final class Cart
{
    public const string SESSION_KEY = 'shop_cart';

    /**
     * One line is capped well below anything the shop stocks. It exists to
     * stop a typed-in 999999 from overflowing a total, not to ration hoodies.
     */
    public const int MAX_QUANTITY = 99;

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    /**
     * The raw contents: "<productId>:<variantId>" (variant may be empty) to a
     * quantity between 1 and {@see MAX_QUANTITY}.
     *
     * @return array<string, int>
     */
    public function all(): array
    {
        $session = $this->session();

        if (null === $session) {
            return [];
        }

        $stored = $session->get(self::SESSION_KEY, []);

        if (!is_array($stored)) {
            return [];
        }

        $clean = [];

        foreach ($stored as $key => $quantity) {
            if (!is_string($key) || !is_int($quantity) || $quantity < 1) {
                continue;
            }

            if (null === self::parseKey($key)) {
                continue;
            }

            $clean[$key] = min($quantity, self::MAX_QUANTITY);
        }

        return $clean;
    }

    /**
     * Splits a stored key back into the ids it holds, or null when it is not
     * one this class wrote.
     *
     * @return array{product: int, variant: int|null}|null
     */
    public static function parseKey(string $key): ?array
    {
        $parts = explode(':', $key);

        if (2 !== count($parts)) {
            return null;
        }

        [$product, $variant] = $parts;

        if ('' === $product || 1 !== preg_match('/^\d+$/', $product)) {
            return null;
        }

        if ('' !== $variant && 1 !== preg_match('/^\d+$/', $variant)) {
            return null;
        }

        return [
            'product' => (int) $product,
            'variant' => '' === $variant ? null : (int) $variant,
        ];
    }

    public static function key(int $productId, ?int $variantId): string
    {
        return $productId.':'.(null === $variantId ? '' : (string) $variantId);
    }

    public function add(Product $product, ?ProductVariant $variant, int $quantity = 1): static
    {
        $key = self::keyFor($product, $variant);

        if (null === $key) {
            return $this;
        }

        $lines = $this->all();

        return $this->write($key, ($lines[$key] ?? 0) + $quantity, $product, $variant);
    }

    public function setQuantity(Product $product, ?ProductVariant $variant, int $quantity): static
    {
        $key = self::keyFor($product, $variant);

        if (null === $key) {
            return $this;
        }

        return $this->write($key, $quantity, $product, $variant);
    }

    public function remove(Product $product, ?ProductVariant $variant): static
    {
        $key = self::keyFor($product, $variant);

        if (null === $key) {
            return $this;
        }

        return $this->removeKey($key);
    }

    public function removeKey(string $key): static
    {
        $lines = $this->all();
        unset($lines[$key]);

        return $this->store($lines);
    }

    public function setQuantityForKey(string $key, int $quantity, int $available): static
    {
        $lines = $this->all();
        $clamped = self::clamp($quantity, $available);

        if (0 === $clamped) {
            unset($lines[$key]);
        } else {
            $lines[$key] = $clamped;
        }

        return $this->store($lines);
    }

    public function clear(): static
    {
        return $this->store([]);
    }

    public function isEmpty(): bool
    {
        return [] === $this->all();
    }

    /**
     * Units in the basket, not lines: the header badge counts things, and two
     * hoodies are two things.
     */
    public function getCount(): int
    {
        return array_sum($this->all());
    }

    public function getLineCount(): int
    {
        return count($this->all());
    }

    public function getQuantity(Product $product, ?ProductVariant $variant): int
    {
        $key = self::keyFor($product, $variant);

        if (null === $key) {
            return 0;
        }

        return $this->all()[$key] ?? 0;
    }

    /**
     * How many units of this product or variant may be bought at once.
     */
    public static function availableStock(Product $product, ?ProductVariant $variant): int
    {
        if (null !== $variant) {
            return $variant->isActive() ? $variant->getStock() : 0;
        }

        return $product->getStock();
    }

    private static function clamp(int $quantity, int $available): int
    {
        if ($quantity < 1 || $available < 1) {
            return 0;
        }

        return max(0, min($quantity, self::MAX_QUANTITY, $available));
    }

    private function write(string $key, int $quantity, Product $product, ?ProductVariant $variant): static
    {
        return $this->setQuantityForKey($key, $quantity, self::availableStock($product, $variant));
    }

    private static function keyFor(Product $product, ?ProductVariant $variant): ?string
    {
        $productId = $product->getId();

        if (null === $productId) {
            return null;
        }

        if (null === $variant) {
            return self::key($productId, null);
        }

        $variantId = $variant->getId();

        return null === $variantId ? null : self::key($productId, $variantId);
    }

    /**
     * @param array<string, int> $lines
     */
    private function store(array $lines): static
    {
        $this->session()?->set(self::SESSION_KEY, $lines);

        return $this;
    }

    /**
     * There is no session on a stateless request - a webhook, say - and a cart
     * that throws there would take the endpoint down with it.
     */
    private function session(): ?SessionInterface
    {
        $request = $this->requestStack->getCurrentRequest();

        if (!$request instanceof Request || !$request->hasSession()) {
            return null;
        }

        return $request->getSession();
    }
}
