<?php

declare(strict_types=1);

namespace App\Twig;

use App\Shop\Cart;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the basket count to the layout.
 *
 * The header renders on every page, and a Twig global would price the cart on
 * every one of them. A function reads only the session, which is already in
 * memory, and returns a number.
 */
final class CartExtension extends AbstractExtension
{
    public function __construct(private readonly Cart $cart)
    {
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cart_count', $this->cartCount(...)),
            new TwigFunction('cart_max_quantity', static fn (): int => Cart::MAX_QUANTITY),
        ];
    }

    public function cartCount(): int
    {
        return $this->cart->getCount();
    }
}
