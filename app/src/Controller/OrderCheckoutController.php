<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\User;
use App\Payment\PaymentsNotConfigured;
use App\Payment\StripeCheckout;
use App\Repository\OrderRepository;
use App\Shop\Cart;
use App\Shop\CartView;
use App\Shop\CartViewBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\Exception\ApiErrorException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Paying for a basket.
 *
 * The same rule as {@see CheckoutController}: nothing here ever marks an order
 * paid. The order is written PENDING from prices this request read out of the
 * database, the member is sent to Stripe, and only the webhook - which has a
 * signature to check - may promote it. Somebody who types the success URL gets
 * a page and nothing else.
 */
#[IsGranted('ROLE_USER')]
final class OrderCheckoutController extends AbstractController
{
    #[Route('/{_locale}/shop/checkout', name: 'shop_checkout_start', requirements: ['_locale' => 'en|lv|ru'], methods: ['POST'])]
    public function start(
        Request $request,
        #[CurrentUser]
        User $user,
        Cart $cart,
        CartViewBuilder $builder,
        StripeCheckout $stripe,
        EntityManagerInterface $entityManager,
        LoggerInterface $logger,
    ): Response {
        if (!$this->isCsrfTokenValid('checkout', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'shop.flash.invalid_token');

            return $this->redirectToRoute('cart_show');
        }

        if (!$stripe->isConfigured()) {
            $this->addFlash('error', 'shop.flash.payments_unavailable');

            return $this->redirectToRoute('cart_show');
        }

        // Building the view re-reads every price and every stock level, and
        // drops anything that has since gone off sale.
        $view = $builder->build($cart);

        if ($view->isEmpty()) {
            $this->addFlash('error', 'shop.flash.empty');

            return $this->redirectToRoute('cart_show');
        }

        if ($view->adjusted) {
            // Something moved between the cart page and this button. Show them
            // the corrected basket rather than charging for the old one.
            $this->addFlash('error', 'shop.cart.adjusted');

            return $this->redirectToRoute('cart_show');
        }

        $order = self::buildOrder($user, $view);

        $entityManager->persist($order);
        $entityManager->flush();

        $locale = $request->getLocale();

        try {
            $session = $stripe->createOrderSession(
                $order,
                // Stripe substitutes the placeholder itself, so it has to
                // survive as a literal rather than be URL-encoded.
                $this->generateUrl('shop_checkout_success', ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL).'?session_id={CHECKOUT_SESSION_ID}',
                $this->generateUrl('shop_checkout_cancel', ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL),
                $locale,
            );
        } catch (ApiErrorException|PaymentsNotConfigured $e) {
            $logger->error('Stripe refused a shop checkout session.', ['exception' => $e, 'order' => $order->getReference()]);

            // Nothing was charged, so leave no pending row behind.
            $entityManager->remove($order);
            $entityManager->flush();

            $this->addFlash('error', 'shop.flash.checkout_failed');

            return $this->redirectToRoute('cart_show');
        }

        $order->setStripeCheckoutSessionId($session->id);
        $entityManager->flush();

        if (null === $session->url) {
            $logger->error('Stripe returned a checkout session with no URL.', ['session' => $session->id]);
            $this->addFlash('error', 'shop.flash.checkout_failed');

            return $this->redirectToRoute('cart_show');
        }

        // The handoff succeeded, so the basket has become an order. Payment is
        // a separate question, and the pending order is what tracks it.
        $cart->clear();

        return $this->redirect($session->url);
    }

    /**
     * Where Stripe sends the member after a successful payment.
     *
     * The order may still be PENDING here: the webhook is a separate
     * connection and can land after the browser redirect. The page says so
     * rather than pretending.
     */
    #[Route('/{_locale}/shop/checkout/success', name: 'shop_checkout_success', requirements: ['_locale' => 'en|lv|ru'], methods: ['GET'])]
    public function success(
        Request $request,
        #[CurrentUser]
        User $user,
        OrderRepository $orders,
    ): Response {
        $sessionId = (string) $request->query->get('session_id', '');
        $order = '' === $sessionId ? null : $orders->findOneByCheckoutSession($sessionId);

        // Only ever show somebody their own purchase.
        if (null !== $order && $order->getUser()->getId() !== $user->getId()) {
            $order = null;
        }

        return $this->render('shop/checkout_success.html.twig', [
            'order' => $order,
        ]);
    }

    /**
     * The member backed out on Stripe's page. Drop the pending order so their
     * account does not accumulate purchases that never happened - but leave
     * the basket alone, because they still want the things in it.
     */
    #[Route('/{_locale}/shop/checkout/cancel', name: 'shop_checkout_cancel', requirements: ['_locale' => 'en|lv|ru'], methods: ['GET'])]
    public function cancel(
        #[CurrentUser]
        User $user,
        OrderRepository $orders,
        EntityManagerInterface $entityManager,
    ): Response {
        foreach ($orders->findPendingFor($user) as $pending) {
            $entityManager->remove($pending);
        }
        $entityManager->flush();

        $this->addFlash('error', 'shop.flash.checkout_cancelled');

        return $this->redirectToRoute('cart_show');
    }

    /**
     * Snapshots the priced basket onto a new PENDING order.
     */
    private static function buildOrder(User $user, CartView $view): Order
    {
        $order = new Order($user);

        foreach ($view->lines as $line) {
            (new OrderItem($order))
                ->setProduct($line->product)
                ->setVariant($line->variant)
                ->setNameSnapshot($line->getName())
                ->setSkuSnapshot($line->getSku())
                ->setUnitPriceCents($line->unitPriceCents)
                ->setQuantity($line->quantity);
        }

        // The lines were attached one at a time; total them once they all are.
        return $order->recalculateTotal();
    }
}
