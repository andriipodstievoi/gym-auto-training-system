<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\TrainerRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Coach profiles, read-only. Availability and booking are M5.
 */
#[Route('/{_locale}/trainers', requirements: ['_locale' => 'en|lv|ru'])]
final class TrainerController extends AbstractController
{
    #[Route('', name: 'trainer_index', methods: ['GET'])]
    public function index(TrainerRepository $trainers): Response
    {
        return $this->render('trainer/index.html.twig', [
            'trainers' => $trainers->findActiveWithBranch(),
        ]);
    }

    #[Route('/{slug}', name: 'trainer_show', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function show(string $slug, TrainerRepository $trainers): Response
    {
        $trainer = $trainers->findOneActiveBySlug($slug);

        if (null === $trainer) {
            throw $this->createNotFoundException(sprintf('No active trainer named "%s".', $slug));
        }

        return $this->render('trainer/show.html.twig', [
            'trainer' => $trainer,
        ]);
    }
}
