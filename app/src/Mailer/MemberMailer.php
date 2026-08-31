<?php

declare(strict_types=1);

namespace App\Mailer;

use App\Entity\User;
use App\Entity\UserMembership;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The mail this site sends to members.
 *
 * Every message goes out in the language on the member's profile, not the
 * language of whichever request triggered it - the webhook that confirms a
 * payment arrives from Stripe with no locale at all.
 */
final readonly class MemberMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
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

        $this->mailer->send($email);
    }
}
