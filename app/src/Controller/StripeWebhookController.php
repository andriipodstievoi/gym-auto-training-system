<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Enum\MembershipStatus;
use App\Mailer\MemberMailer;
use App\Payment\PaymentsNotConfigured;
use App\Payment\StripeCheckout;
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
 * The only place a membership becomes ACTIVE.
 *
 * Deliberately outside the /{_locale} prefix: Stripe is not a browser and has
 * no language. It is a public endpoint, so the signature check is the whole
 * of its security - if the signature cannot be verified, nothing is processed.
 */
final class StripeWebhookController extends AbstractController
{
    #[Route('/webhook/stripe', name: 'app_stripe_webhook', methods: ['POST'])]
    public function __invoke(
        Request $request,
        StripeCheckout $stripe,
        UserMembershipRepository $memberships,
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

        return match ($event->type) {
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded' => $this->confirm($object, $memberships, $entityManager, $mailer, $logger),
            'checkout.session.expired' => $this->expire($object, $memberships, $entityManager),
            default => new Response('Ignored.', Response::HTTP_OK),
        };
    }

    private function confirm(
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

    private function expire(
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
