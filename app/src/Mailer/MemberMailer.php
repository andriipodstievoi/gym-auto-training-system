<?php

declare(strict_types=1);

namespace App\Mailer;

use App\Entity\Booking;
use App\Entity\Message;
use App\Entity\Order;
use App\Entity\User;
use App\Entity\UserMembership;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The mail this site sends to members.
 *
 * Every message goes out in the language on the member's profile, not the
 * language of whichever request triggered it - the webhook that confirms a
 * payment arrives from Stripe with no locale at all.
 *
 * **A mail that cannot be sent is logged, not thrown.** Every message here
 * follows something that already happened: an account was created, a payment
 * landed, a coach answered. Letting a dead SMTP server turn a completed
 * registration into a 500 loses the member's account as far as they can tell -
 * except it does not, because the row is already written, so they retry and
 * are told the email is taken. That is the worst of both outcomes, and it is
 * what this class is here to prevent.
 *
 * Mail that must be delivered rather than attempted does not belong here; it
 * belongs on a queue with a retry policy.
 */
final readonly class MemberMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
        #[Autowire('%env(MAIL_FROM)%')]
        private string $fromAddress,
        #[Autowire('%env(MAIL_FROM_NAME)%')]
        private string $fromName,
    ) {
    }

    public function sendWelcome(User $user): void
    {
        $this->send($user, 'email.welcome.subject', 'email/welcome.html.twig', []);
    }

    public function sendMembershipConfirmation(UserMembership $membership): void
    {
        $this->send(
            $membership->getUser(),
            'email.membership.subject',
            'email/membership_confirmed.html.twig',
            ['membership' => $membership],
        );
    }

    public function sendOrderConfirmation(Order $order): void
    {
        $this->send(
            $order->getUser(),
            'email.order.subject',
            'email/order_confirmed.html.twig',
            ['order' => $order],
        );
    }

    /**
     * Tells the coach somebody wants an hour of their time.
     *
     * A coach with no linked account has nowhere to be written to - the public
     * profile goes up long before the login exists - so this quietly does
     * nothing rather than dereferencing null. The request is still recorded,
     * and still shows on the coach area once they do have one.
     */
    public function sendBookingRequested(Booking $booking): void
    {
        $coach = $booking->getTrainer()->getUser();

        if (null === $coach) {
            return;
        }

        $this->send($coach, 'email.booking_requested.subject', 'email/booking_requested.html.twig', [
            'booking' => $booking,
        ]);
    }

    /**
     * The member's own copy of the request.
     *
     * Deliberately not the coach's mail with a different recipient: the coach
     * is being asked to do something, the member is being told we passed the
     * message on. Sharing a subject line made a member's inbox read as though
     * somebody wanted an hour from them.
     */
    public function sendBookingRequestReceived(Booking $booking): void
    {
        $this->send($booking->getUser(), 'email.booking_requested_member.subject', 'email/booking_requested_member.html.twig', [
            'booking' => $booking,
        ]);
    }

    public function sendBookingConfirmed(Booking $booking): void
    {
        $this->send($booking->getUser(), 'email.booking_confirmed.subject', 'email/booking_confirmed.html.twig', [
            'booking' => $booking,
        ]);
    }

    public function sendBookingDeclined(Booking $booking): void
    {
        $this->send($booking->getUser(), 'email.booking_declined.subject', 'email/booking_declined.html.twig', [
            'booking' => $booking,
        ]);
    }

    /**
     * Goes to whoever did not call the session off, which today is always the
     * coach - only the member has a cancel button. Same null-coach caveat.
     */
    public function sendBookingCancelled(Booking $booking, User $cancelledBy): void
    {
        $coach = $booking->getTrainer()->getUser();
        $recipient = $booking->getUser()->getId() === $cancelledBy->getId() ? $coach : $booking->getUser();

        if (null === $recipient) {
            return;
        }

        $this->send($recipient, 'email.booking_cancelled.subject', 'email/booking_cancelled.html.twig', [
            'booking' => $booking,
        ]);
    }

    /**
     * Nudges the other party in a thread. The recipient is nullable for the
     * same reason: a member may write to a coach who has no login yet, and
     * the message waits for them rather than failing the send.
     */
    public function sendNewMessage(Message $message, ?User $recipient): void
    {
        if (null === $recipient) {
            return;
        }

        $this->send($recipient, 'email.message.subject', 'email/new_message.html.twig', [
            'message' => $message,
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function send(User $user, string $subjectKey, string $template, array $context): void
    {
        $locale = $user->getLocale();

        $email = (new TemplatedEmail())
            ->from(new Address($this->fromAddress, $this->fromName))
            ->to(new Address($user->getEmail(), $user->getFullName()))
            ->subject($this->translator->trans($subjectKey, [], 'messages', $locale))
            ->htmlTemplate($template)
            ->locale($locale)
            ->context($context + ['user' => $user, 'locale' => $locale]);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            // Deliberately swallowed - see the note on this class. The subject
            // key identifies the message without putting the member's address
            // in the log.
            $this->logger->error('A member email could not be sent.', [
                'exception' => $e,
                'subject' => $subjectKey,
                'template' => $template,
            ]);
        }
    }
}
