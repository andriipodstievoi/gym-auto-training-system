<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Payment\StripeCheckout;
use App\Repository\ProductRepository;
use App\Repository\ProductVariantRepository;
use App\Shop\Cart;
use App\Shop\CartViewBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The basket.
 *
 * Usable signed out on purpose - being asked to register before you can even
 * see a total is how a shop loses the sale. The account is only needed at
 * checkout, where there is something to ship and someone to charge.
 *
 * Every write is a POST with a CSRF token, exactly as membership checkout is:
 * a cart is state changed on somebody's behalf, so a GET must not do it.
 */
final class CartController extends AbstractController
{
    /**
     * The shop reuses the membership checkout's token id: it is already in
     * framework.csrf_protection.stateless_token_ids, and both are the same
     * thing - a hand-written buy form rather than a Symfony form type.
     */
    private const string CSRF_ID = 'cart';

    #[Route('/{_locale}/cart', name: 'cart_show', requirements: ['_locale' => 'en|lv|ru'], methods: ['GET'])]
    public function show(Cart $cart, CartViewBuilder $builder, StripeCheckout $stripe): Response
    {
        $view = $builder->build($cart);

        // Say so when hydrating had to change something, rather than letting
        // the total move without explanation.
        if ($view->adjusted) {
            $this->addFlash('error', 'shop.cart.adjusted');
        }

        return $this->render('cart/show.html.twig', [
            'cart' => $view,
            // With no keys configured there is nothing to click, so the page
            // says so plainly rather than rendering a dead button.
            'checkout_available' => $stripe->isConfigured(),
        ]);
    }

    #[Route('/{_locale}/cart/add', name: 'cart_add', requirements: ['_locale' => 'en|lv|ru'], methods: ['POST'])]
    public function add(
        Request $request,
        Cart $cart,
        ProductRepository $products,
        ProductVariantRepository $variants,
    ): Response {
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'shop.flash.invalid_token');

            return $this->redirectToRoute('cart_show');
        }

        $product = $products->findOneActiveBySlug((string) $request->request->get('slug', ''));

        if (!$product instanceof Product) {
            throw $this->createNotFoundException('No product on sale matches that slug.');
        }

        $variant = self::resolveVariant($product, $request->request->get('variant'), $variants);

        if ($product->hasVariants() && null === $variant) {
            $this->addFlash('error', 'shop.flash.out_of_stock');

            return $this->redirectToRoute('shop_product', ['slug' => $product->getSlug()]);
        }

        if (Cart::availableStock($product, $variant) < 1) {
            $this->addFlash('error', 'shop.flash.out_of_stock');

            return $this->redirectToRoute('shop_product', ['slug' => $product->getSlug()]);
        }

        $cart->add($product, $variant, self::quantity($request));
        $this->addFlash('success', 'shop.flash.added');

        return $this->redirectToRoute('cart_show');
    }

    #[Route('/{_locale}/cart/update', name: 'cart_update', requirements: ['_locale' => 'en|lv|ru'], methods: ['POST'])]
    public function update(
        Request $request,
        Cart $cart,
        ProductRepository $products,
        ProductVariantRepository $variants,
    ): Response {
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'shop.flash.invalid_token');

            return $this->redirectToRoute('cart_show');
        }

        $key = (string) $request->request->get('key', '');
        $parsed = Cart::parseKey($key);

        if (null === $parsed) {
            return $this->redirectToRoute('cart_show');
        }

        $product = $products->findByIdsIndexed([$parsed['product']])[$parsed['product']] ?? null;
        $variant = null === $parsed['variant']
            ? null
            : ($variants->findByIdsIndexed([$parsed['variant']])[$parsed['variant']] ?? null);

        // Whatever it pointed at is gone; the read model would drop it anyway.
        if (!$product instanceof Product) {
            $cart->removeKey($key);

            return $this->redirectToRoute('cart_show');
        }

        $cart->setQuantityForKey($key, self::quantity($request), Cart::availableStock($product, $variant));
        $this->addFlash('success', 'shop.flash.updated');

        return $this->redirectToRoute('cart_show');
    }

    #[Route('/{_locale}/cart/remove', name: 'cart_remove', requirements: ['_locale' => 'en|lv|ru'], methods: ['POST'])]
    public function remove(Request $request, Cart $cart): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'shop.flash.invalid_token');

            return $this->redirectToRoute('cart_show');
        }

        $cart->removeKey((string) $request->request->get('key', ''));
        $this->addFlash('success', 'shop.flash.removed');

        return $this->redirectToRoute('cart_show');
    }

    /**
     * The variant the form picked, but only if it really belongs to this
     * product and is still on sale.
     */
    private static function resolveVariant(
        Product $product,
        mixed $raw,
        ProductVariantRepository $variants,
    ): ?ProductVariant {
        if (!is_string($raw) || 1 !== preg_match('/^\d+$/', $raw)) {
            return null;
        }

        $id = (int) $raw;
        $variant = $variants->findByIdsIndexed([$id])[$id] ?? null;

        if (!$variant instanceof ProductVariant || !$variant->isActive()) {
            return null;
        }

        return $variant->getProduct()->getId() === $product->getId() ? $variant : null;
    }

    private static function quantity(Request $request): int
    {
        $raw = $request->request->get('quantity', '1');

        if (!is_string($raw) || 1 !== preg_match('/^\d+$/', $raw)) {
            return 1;
        }

        return min((int) $raw, Cart::MAX_QUANTITY);
    }
}
