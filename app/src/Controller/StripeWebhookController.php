<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Enum\MembershipStatus;
use App\Domain\Enum\OrderStatus;
use App\Entity\Order;
use App\Mailer\MemberMailer;
use App\Payment\PaymentsNotConfigured;
use App\Payment\StripeCheckout;
use App\Repository\OrderRepository;
use App\Repository\UserMembershipRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\Checkout\Session;
use Stripe\Exception\SignatureVerificationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use UnexpectedValueException;

/**
 * The only place a membership becomes ACTIVE or an order becomes PAID.
 *
 * Deliberately outside the /{_locale} prefix: Stripe is not a browser and has
 * no language. It is a public endpoint, so the signature check is the whole
 * of its security - if the signature cannot be verified, nothing is processed.
 *
 * One endpoint now serves two kinds of purchase. Which one a session belongs
 * to is decided by its metadata - an order carries order_id - and never by
 * guessing from the amount or the line items.
 */
final class StripeWebhookController extends AbstractController
{
    #[Route('/webhook/stripe', name: 'app_stripe_webhook', methods: ['POST'])]
    public function __invoke(
        Request $request,
        StripeCheckout $stripe,
        UserMembershipRepository $memberships,
        OrderRepository $orders,
        EntityManagerInterface $entityManager,
        MemberMailer $mailer,
        LoggerInterface $logger,
    ): Response {
        if (!$stripe->canVerifyWebhooks()) {
            $logger->error('A Stripe webhook arrived but STRIPE_WEBHOOK_SECRET is empty, so it cannot be trusted.');

            return new Response('Webhooks are not configured.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            $event = $stripe->verifyWebhook(
                $request->getContent(),
                (string) $request->headers->get('stripe-signature', ''),
            );
        } catch (SignatureVerificationException $e) {
            $logger->warning('Rejected a Stripe webhook with a bad signature.', ['exception' => $e]);

            return new Response('Invalid signature.', Response::HTTP_BAD_REQUEST);
        } catch (UnexpectedValueException|PaymentsNotConfigured $e) {
            $logger->warning('Rejected an unreadable Stripe webhook.', ['exception' => $e]);

            return new Response('Invalid payload.', Response::HTTP_BAD_REQUEST);
        }

        $object = $event->data->object;

        if (!$object instanceof Session) {
            return new Response('Ignored.', Response::HTTP_OK);
        }

        $isOrder = null !== $this->orderIdFrom($object);

        return match ($event->type) {
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded' => $isOrder
                ? $this->confirmOrder($object, $orders, $entityManager, $mailer, $logger)
                : $this->confirmMembership($object, $memberships, $entityManager, $mailer, $logger),
            'checkout.session.expired' => $isOrder
                ? $this->expireOrder($object, $orders, $entityManager)
                : $this->expireMembership($object, $memberships, $entityManager),
            default => new Response('Ignored.', Response::HTTP_OK),
        };
    }

    /**
     * The order id a checkout session carries, or null when the session is not
     * a shop order at all.
     *
     * Stripe types metadata loosely, so this narrows rather than trusting it:
     * anything that is not a positive integer treats the session as a
     * membership, which is the older and safer of the two paths.
     */
    private function orderIdFrom(Session $session): ?int
    {
        $metadata = $session->metadata;

        if (null === $metadata) {
            return null;
        }

        $raw = $metadata['order_id'] ?? null;

        if (!is_string($raw) && !is_int($raw)) {
            return null;
        }

        $id = filter_var($raw, FILTER_VALIDATE_INT);

        return is_int($id) && $id > 0 ? $id : null;
    }

    private function confirmOrder(
        Session $session,
        OrderRepository $orders,
        EntityManagerInterface $entityManager,
        MemberMailer $mailer,
        LoggerInterface $logger,
    ): Response {
        // Card payments settle before the redirect; some methods do not, and
        // send an unpaid completed event first. Wait for the money either way.
        if ('paid' !== $session->payment_status) {
            return new Response('Not paid yet.', Response::HTTP_OK);
        }

        $order = $orders->findOneByCheckoutSession($session->id);

        if (null === $order) {
            $logger->warning('A Stripe webhook referenced an unknown order checkout session.', ['session' => $session->id]);

            // 200, not 404: retrying will not conjure the row, and a failing
            // endpoint eventually gets disabled in the Stripe dashboard.
            return new Response('Unknown session.', Response::HTTP_OK);
        }

        // Stripe retries until it gets a 2xx, so this can arrive more than
        // once. Confirming twice must not draw stock down again or resend the
        // receipt.
        if (OrderStatus::PENDING !== $order->getStatus()) {
            return new Response('Already handled.', Response::HTTP_OK);
        }

        $paymentIntent = $session->payment_intent;
        if (is_string($paymentIntent)) {
            $order->setStripePaymentIntentId($paymentIntent);
        }

        $order->markPaid(new DateTimeImmutable());
        $this->drawDownStock($order);
        $entityManager->flush();

        $mailer->sendOrderConfirmation($order);

        return new Response('Confirmed.', Response::HTTP_OK);
    }

    /**
     * Take what was actually bought out of stock.
     *
     * This happens on payment rather than at checkout on purpose: somebody who
     * reaches Stripe and walks away must not hold stock hostage, and an order
     * that expires never reaches here at all.
     *
     * Stock is floored at zero because two members can pay for the last item
     * within the same second. Refusing the second payment is not an option by
     * this point - Stripe already has the money - so overselling one unit and
     * sorting it out at the counter is the honest failure mode.
     */
    private function drawDownStock(Order $order): void
    {
        foreach ($order->getItems() as $item) {
            $variant = $item->getVariant();

            if (null !== $variant) {
                $variant->setStock(max(0, $variant->getStock() - $item->getQuantity()));

                continue;
            }

            $product = $item->getProduct();

            if (null !== $product) {
                $product->setStock(max(0, $product->getStock() - $item->getQuantity()));
            }
        }
    }

    private function expireOrder(
        Session $session,
        OrderRepository $orders,
        EntityManagerInterface $entityManager,
    ): Response {
        $order = $orders->findOneByCheckoutSession($session->id);

        if (null !== $order && OrderStatus::PENDING === $order->getStatus()) {
            $order->setStatus(OrderStatus::EXPIRED);
            $entityManager->flush();
        }

        return new Response('Expired.', Response::HTTP_OK);
    }

    private function confirmMembership(
        Session $session,
        UserMembershipRepository $memberships,
        EntityManagerInterface $entityManager,
        MemberMailer $mailer,
        LoggerInterface $logger,
    ): Response {
        // Card payments settle before the redirect; some methods do not, and
        // send an unpaid completed event first. Wait for the money either way.
        if ('paid' !== $session->payment_status) {
            return new Response('Not paid yet.', Response::HTTP_OK);
        }

        $membership = $memberships->findOneByCheckoutSession($session->id);

        if (null === $membership) {
            $logger->warning('A Stripe webhook referenced an unknown checkout session.', ['session' => $session->id]);

            // 200, not 404: retrying will not conjure the row, and a failing
            // endpoint eventually gets disabled in the Stripe dashboard.
            return new Response('Unknown session.', Response::HTTP_OK);
        }

        // Stripe retries until it gets a 2xx, so this can arrive more than
        // once. Confirming twice must not extend the period or resend the mail.
        if (MembershipStatus::PENDING !== $membership->getStatus()) {
            return new Response('Already handled.', Response::HTTP_OK);
        }

        $paymentIntent = $session->payment_intent;
        if (is_string($paymentIntent)) {
            $membership->setStripePaymentIntentId($paymentIntent);
        }

        $membership->activate(new DateTimeImmutable());
        $entityManager->flush();

        $mailer->sendMembershipConfirmation($membership);

        return new Response('Confirmed.', Response::HTTP_OK);
    }

    private function expireMembership(
        Session $session,
        UserMembershipRepository $memberships,
        EntityManagerInterface $entityManager,
    ): Response {
        $membership = $memberships->findOneByCheckoutSession($session->id);

        if (null !== $membership && MembershipStatus::PENDING === $membership->getStatus()) {
            $membership->setStatus(MembershipStatus::EXPIRED);
            $entityManager->flush();
        }

        return new Response('Expired.', Response::HTTP_OK);
    }
}
