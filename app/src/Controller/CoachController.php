<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Trainer;
use App\Entity\TrainerAvailability;
use App\Entity\User;
use App\Form\TrainerAvailabilityFormType;
use App\Mailer\MemberMailer;
use App\Repository\BookingRepository;
use App\Repository\TrainerAvailabilityRepository;
use App\Repository\TrainerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The coach's own area: their diary and the hours they work.
 *
 * There is no ROLE_TRAINER and no second firewall. A coach is a User some
 * Trainer row points at, and every action here resolves that row from the
 * current login - so authorisation is a fact about the data rather than a role
 * somebody has to remember to grant. An account with no trainer row gets a 403
 * rather than an empty schedule, because an empty schedule reads like a bug.
 *
 * /{_locale}/coach is not in security.yaml's access_control, so the attribute
 * on this class is what keeps anonymous visitors out. That is deliberate: the
 * rule that matters here is ownership, and ownership cannot be expressed as a
 * path pattern.
 */
#[Route('/{_locale}/coach', requirements: ['_locale' => 'en|lv|ru'])]
#[IsGranted('ROLE_USER')]
final class CoachController extends AbstractController
{
    /**
     * Shared with the member side: confirming, declining and cancelling are
     * the same conversation about the same booking.
     */
    private const string CSRF_ID = 'booking';

    #[Route('', name: 'coach_dashboard', methods: ['GET'])]
    public function dashboard(
        #[CurrentUser]
        User $user,
        TrainerRepository $trainers,
        BookingRepository $bookings,
        ClockInterface $clock,
    ): Response {
        $trainer = $this->coachOf($user, $trainers);

        return $this->render('coach/dashboard.html.twig', [
            'trainer' => $trainer,
            'bookings' => $bookings->findUpcomingForTrainer($trainer, $clock->now()),
        ]);
    }

    #[Route('/bookings/{id}/respond', name: 'coach_booking_respond', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function respond(
        int $id,
        Request $request,
        #[CurrentUser]
        User $user,
        TrainerRepository $trainers,
        BookingRepository $bookings,
        EntityManagerInterface $entityManager,
        MemberMailer $mailer,
        ClockInterface $clock,
    ): Response {
        $trainer = $this->coachOf($user, $trainers);

        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'coach.flash.invalid_token');

            return $this->redirectToRoute('coach_dashboard');
        }

        $booking = $bookings->find($id);

        if (null === $booking) {
            throw $this->createNotFoundException('No booking with that id.');
        }

        // Another coach's diary. 404 rather than 403: whether a booking exists
        // is itself none of their business.
        if ($booking->getTrainer()->getId() !== $trainer->getId()) {
            throw $this->createNotFoundException('That booking belongs to another coach.');
        }

        // Only an open request can be answered. Confirming an hour that was
        // already cancelled would un-cancel it behind the member's back.
        if (!$booking->getStatus()->awaitsResponse()) {
            $this->addFlash('error', 'booking.flash.not_allowed');

            return $this->redirectToRoute('coach_dashboard');
        }

        $confirm = 'confirm' === $request->request->get('decision');
        $now = $clock->now();

        if ($confirm) {
            $booking->confirm($now);
        } else {
            $booking->decline($now);
        }

        $entityManager->flush();

        if ($confirm) {
            $mailer->sendBookingConfirmed($booking);
            $this->addFlash('success', 'coach.flash.confirmed');
        } else {
            $mailer->sendBookingDeclined($booking);
            $this->addFlash('success', 'coach.flash.declined');
        }

        return $this->redirectToRoute('coach_dashboard');
    }

    #[Route('/availability', name: 'coach_availability', methods: ['GET', 'POST'])]
    public function availability(
        Request $request,
        #[CurrentUser]
        User $user,
        TrainerRepository $trainers,
        TrainerAvailabilityRepository $availability,
        EntityManagerInterface $entityManager,
    ): Response {
        $trainer = $this->coachOf($user, $trainers);

        $window = new TrainerAvailability($trainer);
        $form = $this->createForm(TrainerAvailabilityFormType::class, $window);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($window);
            $entityManager->flush();
            $this->addFlash('success', 'coach.flash.availability_added');

            return $this->redirectToRoute('coach_availability');
        }

        $response = new Response(status: $form->isSubmitted() && !$form->isValid()
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK);

        return $this->render('coach/availability.html.twig', [
            'trainer' => $trainer,
            'windows' => $availability->findAllFor($trainer),
            'form' => $form,
        ], $response);
    }

    #[Route('/availability/{id}/remove', name: 'coach_availability_remove', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function removeAvailability(
        int $id,
        Request $request,
        #[CurrentUser]
        User $user,
        TrainerRepository $trainers,
        TrainerAvailabilityRepository $availability,
        EntityManagerInterface $entityManager,
    ): Response {
        $trainer = $this->coachOf($user, $trainers);

        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'coach.flash.invalid_token');

            return $this->redirectToRoute('coach_availability');
        }

        $window = $availability->find($id);

        if (null === $window || $window->getTrainer()->getId() !== $trainer->getId()) {
            throw $this->createNotFoundException('No such window in your week.');
        }

        $entityManager->remove($window);
        $entityManager->flush();
        $this->addFlash('success', 'coach.flash.availability_removed');

        return $this->redirectToRoute('coach_availability');
    }

    /**
     * The trainer row this login is, or a refusal.
     */
    private function coachOf(User $user, TrainerRepository $trainers): Trainer
    {
        $trainer = $trainers->findOneByUser($user);

        if (null === $trainer) {
            throw $this->createAccessDeniedException('This account is not a coach.');
        }

        return $trainer;
    }
}
