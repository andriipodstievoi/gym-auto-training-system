<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    /**
     * Bare "/" has no locale, so send visitors to the default one.
     */
    #[Route('/', name: 'home_root', methods: ['GET'])]
    public function root(): Response
    {
        return $this->redirectToRoute('home', ['_locale' => $this->getParameter('kernel.default_locale')]);
    }

    #[Route('/{_locale}', name: 'home', requirements: ['_locale' => 'en|lv|ru'], methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('home/index.html.twig', [
            'milestones' => self::MILESTONES,
        ]);
    }

    /**
     * Temporary roadmap shown on the landing page. Replaced by real content
     * as each milestone lands.
     *
     * @var list<array{id: string, title: string, done: bool, detail: string}>
     */
    private const MILESTONES = [
        ['id' => 'M0', 'title' => 'Foundation', 'done' => true,
            'detail' => 'Symfony 7.4 LTS, Tailwind 4, Alpine, MySQL, Redis, EN/LV/RU routing, Docker and CI.'],
        ['id' => 'M1', 'title' => 'Domain model & admin', 'done' => true,
            'detail' => 'Entities, migrations, fixtures and a back office for branches, plans, products and trainers.'],
        ['id' => 'M2', 'title' => 'Public site & gym map', 'done' => true,
            'detail' => 'Branch pages, a Leaflet map of Riga locations, and a clickable SVG floor plan.'],
        ['id' => 'M3', 'title' => 'Accounts & memberships', 'done' => true,
            'detail' => 'Registration, login, membership tiers and Stripe test-mode checkout with webhooks.'],
        ['id' => 'M4', 'title' => 'Shop', 'done' => true,
            'detail' => 'Catalogue, variants, cart, orders and order history for clothing and supplements.'],
        ['id' => 'M5', 'title' => 'Trainers & booking', 'done' => true,
            'detail' => 'Coach profiles, availability, session booking and messaging with email notifications.'],
        ['id' => 'M6', 'title' => 'Automatic training system', 'done' => false,
            'detail' => 'The assessment, the Python rule engine, the LLM coaching layer and PDF plan export.'],
        ['id' => 'M7', 'title' => 'Hardening', 'done' => false,
            'detail' => 'PHPUnit and pytest coverage, PHPStan level up, documentation and screenshots.'],
    ];
}
