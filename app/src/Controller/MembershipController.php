<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Payment\StripeCheckout;
use App\Repository\MembershipPlanRepository;
use App\Repository\UserMembershipRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The price list, and where buying a membership starts.
 */
final class MembershipController extends AbstractController
{
    #[Route('/{_locale}/memberships', name: 'membership_index', requirements: ['_locale' => 'en|lv|ru'], methods: ['GET'])]
    public function index(
        MembershipPlanRepository $plans,
        UserMembershipRepository $memberships,
        StripeCheckout $stripe,
        #[CurrentUser]
        ?User $user,
    ): Response {
        return $this->render('membership/index.html.twig', [
            'plans' => $plans->findActiveOrdered(),
            // With no keys configured there is nothing to click, so the page
            // says so plainly rather than offering a button that cannot work.
            'checkout_available' => $stripe->isConfigured(),
            'current' => null === $user ? null : $memberships->findCurrentFor($user),
        ]);
    }
}
