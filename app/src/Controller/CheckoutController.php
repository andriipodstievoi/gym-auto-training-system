<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Entity\UserMembership;
use App\Payment\PaymentsNotConfigured;
use App\Payment\StripeCheckout;
use App\Repository\MembershipPlanRepository;
use App\Repository\UserMembershipRepository;
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
 * Buying a membership.
 *
 * Nothing here ever marks a membership as paid. The row is created PENDING,
 * the member is sent to Stripe, and only {@see StripeWebhookController} - which
 * has a signature to check - is allowed to promote it. A member who reaches
 * the success page by typing the URL gets nothing.
 */
#[IsGranted('ROLE_USER')]
final class CheckoutController extends AbstractController
{
    #[Route('/{_locale}/memberships/{slug}/checkout', name: 'app_checkout_start', requirements: ['_locale' => 'en|lv|ru', 'slug' => '[a-z0-9-]+'], methods: ['POST'])]
    public function start(
        string $slug,
        Request $request,
        #[CurrentUser]
        User $user,
        MembershipPlanRepository $plans,
        UserMembershipRepository $memberships,
        StripeCheckout $stripe,
        EntityManagerInterface $entityManager,
        LoggerInterface $logger,
    ): Response {
        $plan = $plans->findOneActiveBySlug($slug);

        if (null === $plan) {
            throw $this->createNotFoundException(\sprintf('No active membership plan is called "%s".', $slug));
        }

        if (!$this->isCsrfTokenValid('checkout', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'membership.flash.invalid_token');

            return $this->redirectToRoute('membership_index');
        }

        if (!$stripe->isConfigured()) {
            $this->addFlash('error', 'membership.flash.payments_unavailable');

            return $this->redirectToRoute('membership_index');
        }

        if (null !== $memberships->findCurrentFor($user)) {
            $this->addFlash('error', 'membership.flash.already_member');

            return $this->redirectToRoute('app_account');
        }

        $membership = new UserMembership($user, $plan);
        $entityManager->persist($membership);
        $entityManager->flush();

        $locale = $request->getLocale();

        try {
            $session = $stripe->createSession(
                $membership,
                // Stripe substitutes the placeholder itself, so it has to
                // survive as a literal rather than be URL-encoded.
                $this->generateUrl('app_checkout_success', ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL).'?session_id={CHECKOUT_SESSION_ID}',
                $this->generateUrl('app_checkout_cancel', ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL),
                $locale,
            );
        } catch (ApiErrorException|PaymentsNotConfigured $e) {
            $logger->error('Stripe refused a checkout session.', ['exception' => $e, 'plan' => $plan->getSlug()]);

            // Nothing was charged, so leave no pending row behind to confuse
            // the member's account page.
            $entityManager->remove($membership);
            $entityManager->flush();

            $this->addFlash('error', 'membership.flash.checkout_failed');

            return $this->redirectToRoute('membership_index');
        }

        $membership->setStripeCheckoutSessionId($session->id);
        $entityManager->flush();

        if (null === $session->url) {
            $logger->error('Stripe returned a checkout session with no URL.', ['session' => $session->id]);

            // Same failure as the catch above, so the same cleanup: nothing was
            // charged and there is nowhere to send the member, so the membership
            // must not sit PENDING in their account for ever.
            $entityManager->remove($membership);
            $entityManager->flush();

            $this->addFlash('error', 'membership.flash.checkout_failed');

            return $this->redirectToRoute('membership_index');
        }

        return $this->redirect($session->url);
    }

    /**
     * Where Stripe sends the member after a successful payment.
     *
     * The membership may still be PENDING here: the webhook is a separate
     * connection and can land after the browser redirect. The page says so
     * rather than pretending, and the account page tells the same truth.
     */
    #[Route('/{_locale}/account/checkout/success', name: 'app_checkout_success', requirements: ['_locale' => 'en|lv|ru'], methods: ['GET'])]
    public function success(
        Request $request,
        #[CurrentUser]
        User $user,
        UserMembershipRepository $memberships,
    ): Response {
        $sessionId = (string) $request->query->get('session_id', '');
        $membership = '' === $sessionId ? null : $memberships->findOneByCheckoutSession($sessionId);

        // Only ever show somebody their own purchase.
        if (null !== $membership && $membership->getUser()->getId() !== $user->getId()) {
            $membership = null;
        }

        return $this->render('account/checkout_success.html.twig', [
            'membership' => $membership,
        ]);
    }

    /**
     * The member backed out on Stripe's page. Drop the pending row so their
     * account does not accumulate purchases that never happened.
     */
    #[Route('/{_locale}/account/checkout/cancel', name: 'app_checkout_cancel', requirements: ['_locale' => 'en|lv|ru'], methods: ['GET'])]
    public function cancel(
        #[CurrentUser]
        User $user,
        UserMembershipRepository $memberships,
        EntityManagerInterface $entityManager,
    ): Response {
        foreach ($memberships->findPendingFor($user) as $pending) {
            $entityManager->remove($pending);
        }
        $entityManager->flush();

        $this->addFlash('error', 'membership.flash.checkout_cancelled');

        return $this->redirectToRoute('membership_index');
    }
}
