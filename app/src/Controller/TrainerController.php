<?php

declare(strict_types=1);

namespace App\Controller;

use App\Booking\SlotFinder;
use App\Entity\User;
use App\Repository\TrainerRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Coach profiles.
 *
 * The profile now carries the front of the booking funnel: the rate, the next
 * few free hours and a way to write to the coach. Everything it shows is still
 * read-only - the picker itself lives in {@see BookingController}.
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
    public function show(
        string $slug,
        TrainerRepository $trainers,
        SlotFinder $slotFinder,
        #[CurrentUser]
        ?User $user = null,
    ): Response {
        $trainer = $trainers->findOneActiveBySlug($slug);

        if (null === $trainer) {
            throw $this->createNotFoundException(sprintf('No active trainer named "%s".', $slug));
        }

        return $this->render('trainer/show.html.twig', [
            'trainer' => $trainer,
            'next_slots' => $slotFinder->findNextSlots($trainer),
            // A coach looking at their own profile is not a prospective
            // member, and a thread with yourself has nowhere to go.
            'can_message' => null !== $user && $trainer->getUser()?->getId() !== $user->getId(),
        ]);
    }
}
