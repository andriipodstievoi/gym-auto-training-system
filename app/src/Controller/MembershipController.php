<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\MembershipPlanRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Membership tiers, read-only. Buying one is M3.
 */
final class MembershipController extends AbstractController
{
    #[Route('/{_locale}/memberships', name: 'membership_index', requirements: ['_locale' => 'en|lv|ru'], methods: ['GET'])]
    public function index(MembershipPlanRepository $plans): Response
    {
        return $this->render('membership/index.html.twig', [
            'plans' => $plans->findActiveOrdered(),
        ]);
    }
}
