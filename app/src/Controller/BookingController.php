<?php

declare(strict_types=1);

namespace App\Controller;

use App\Booking\SlotFinder;
use App\Entity\Booking;
use App\Entity\Trainer;
use App\Entity\User;
use App\Mailer\MemberMailer;
use App\Repository\BookingRepository;
use App\Repository\TrainerRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Booking an hour of a coach's time, from the member's side.
 *
 * The picker is readable signed out for the same reason the shop is: being
 * asked to register before you can even see whether a coach has a Tuesday free
 * is how a gym loses the enquiry. The account is only needed to actually ask.
 *
 * Nothing a booking form posts is trusted. The slot is re-derived from the
 * coach's availability minus their live bookings, and the price comes off the
 * trainer row rather than out of a hidden field.
 */
final class BookingController extends AbstractController
{
    /**
     * Its own token id, separate from cart and checkout: asking a coach for an
     * hour is not the same kind of act as handing money to Stripe.
     */
    private const string CSRF_ID = 'booking';

    #[Route(
        '/{_locale}/trainers/{slug}/book',
        name: 'trainer_booking_slots',
        requirements: ['_locale' => 'en|lv|ru', 'slug' => '[a-z0-9-]+'],
        methods: ['GET'],
    )]
    public function slots(string $slug, TrainerRepository $trainers, SlotFinder $slotFinder): Response
    {
        $trainer = self::activeTrainer($slug, $trainers);

        return $this->render('booking/slots.html.twig', [
            'trainer' => $trainer,
            'days' => $slotFinder->findForTrainer($trainer),
        ]);
    }

    #[Route(
        '/{_locale}/trainers/{slug}/book',
        name: 'booking_create',
        requirements: ['_locale' => 'en|lv|ru', 'slug' => '[a-z0-9-]+'],
        methods: ['POST'],
    )]
    #[IsGranted('ROLE_USER')]
    public function create(
        string $slug,
        Request $request,
        #[CurrentUser]
        User $user,
        TrainerRepository $trainers,
        SlotFinder $slotFinder,
        EntityManagerInterface $entityManager,
        MemberMailer $mailer,
        ClockInterface $clock,
    ): Response {
        $trainer = self::activeTrainer($slug, $trainers);

        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'booking.flash.invalid_token');

            return $this->redirectToRoute('trainer_booking_slots', ['slug' => $trainer->getSlug()]);
        }

        $startsAt = self::parseSlot($request->request->get('slot'));

        // Re-derived, never trusted: between rendering the picker and this
        // click, somebody else may have taken the hour or the coach may have
        // dropped the window it came from.
        $slot = null === $startsAt ? null : $slotFinder->findBookableSlot($trainer, $startsAt);

        if (null === $slot) {
            $this->addFlash('error', 'booking.flash.slot_taken');

            return $this->render('booking/slots.html.twig', [
                'trainer' => $trainer,
                'days' => $slotFinder->findForTrainer($trainer),
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $booking = new Booking($trainer, $user, $slot->startsAt, $slot->endsAt, $clock->now());
        $booking->setNotes(self::notes($request));

        try {
            $entityManager->persist($booking);
            $entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Two members clicked the same hour in the same second. The index
            // is what actually decides it; this is how the loser hears.
            $this->addFlash('error', 'booking.flash.slot_taken');

            return $this->redirectToRoute('trainer_booking_slots', ['slug' => $trainer->getSlug()]);
        }

        $mailer->sendBookingRequested($booking);
        $mailer->sendBookingRequestReceived($booking);

        $this->addFlash('success', 'booking.flash.requested');

        return $this->redirectToRoute('app_account_bookings');
    }

    #[Route(
        '/{_locale}/account/bookings',
        name: 'app_account_bookings',
        requirements: ['_locale' => 'en|lv|ru'],
        methods: ['GET'],
    )]
    #[IsGranted('ROLE_USER')]
    public function mine(
        #[CurrentUser]
        User $user,
        BookingRepository $bookings,
        ClockInterface $clock,
    ): Response {
        $now = $clock->now();

        return $this->render('account/bookings.html.twig', [
            'bookings' => $bookings->findForMember($user, $now),
            'now' => $now,
        ]);
    }

    #[Route(
        '/{_locale}/account/bookings/{id}/cancel',
        name: 'booking_cancel',
        requirements: ['_locale' => 'en|lv|ru', 'id' => '\d+'],
        methods: ['POST'],
    )]
    #[IsGranted('ROLE_USER')]
    public function cancel(
        int $id,
        Request $request,
        #[CurrentUser]
        User $user,
        BookingRepository $bookings,
        EntityManagerInterface $entityManager,
        MemberMailer $mailer,
        ClockInterface $clock,
    ): Response {
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'booking.flash.invalid_token');

            return $this->redirectToRoute('app_account_bookings');
        }

        $booking = $bookings->find($id);

        if (null === $booking) {
            throw $this->createNotFoundException('No booking with that id.');
        }

        // Somebody else's diary. Refused with the same message whether it is
        // theirs or does not exist, so this cannot be used to count bookings.
        if ($booking->getUser()->getId() !== $user->getId()) {
            $this->addFlash('error', 'booking.flash.not_allowed');

            return $this->redirectToRoute('app_account_bookings');
        }

        $now = $clock->now();

        if (!$booking->isCancellableBy($user, $now)) {
            $this->addFlash('error', 'booking.flash.past');

            return $this->redirectToRoute('app_account_bookings');
        }

        $booking->cancel($now);
        $entityManager->flush();

        $mailer->sendBookingCancelled($booking, $user);
        $this->addFlash('success', 'booking.flash.cancelled');

        return $this->redirectToRoute('app_account_bookings');
    }

    private function activeTrainer(string $slug, TrainerRepository $trainers): Trainer
    {
        $trainer = $trainers->findOneActiveBySlug($slug);

        if (null === $trainer) {
            throw $this->createNotFoundException(sprintf('No active trainer named "%s".', $slug));
        }

        return $trainer;
    }

    /**
     * The slot a form posted, as a UTC instant. Anything that is not exactly
     * the shape the picker renders is simply not a slot.
     */
    private static function parseSlot(mixed $raw): ?DateTimeImmutable
    {
        if (!is_string($raw)) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i', $raw, new DateTimeZone('UTC'));

        return false === $parsed ? null : $parsed->setTime(
            (int) $parsed->format('H'),
            (int) $parsed->format('i'),
        );
    }

    private static function notes(Request $request): ?string
    {
        $raw = $request->request->get('notes');

        if (!is_string($raw) || '' === trim($raw)) {
            return null;
        }

        return mb_substr(trim($raw), 0, 2000);
    }
}
