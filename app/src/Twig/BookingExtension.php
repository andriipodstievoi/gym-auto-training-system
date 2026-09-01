<?php

declare(strict_types=1);

namespace App\Twig;

use App\Booking\SlotFinder;
use App\Entity\User;
use App\Repository\MessageRepository;
use App\Repository\TrainerRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The three things booking and messaging need from every template.
 *
 * gym_timezone() keeps one string out of a dozen templates: every stored
 * instant is UTC, and every one of them has to be printed in Riga.
 *
 * unread_messages() and is_coach() are functions rather than globals for the
 * same reason cart_count() is - the header renders on every page, and a global
 * would run both queries on pages that show neither.
 */
final class BookingExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly MessageRepository $messages,
        private readonly TrainerRepository $trainers,
    ) {
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('gym_timezone', static fn (): string => SlotFinder::TIMEZONE),
            new TwigFunction('unread_messages', $this->unreadMessages(...)),
            new TwigFunction('is_coach', $this->isCoach(...)),
        ];
    }

    /**
     * Whether the current login is one a trainer row points at. The header
     * only shows the coach area to somebody who has one, and the controller
     * refuses everyone else regardless of what the header rendered.
     */
    public function isCoach(): bool
    {
        $user = $this->security->getUser();

        return $user instanceof User && null !== $this->trainers->findOneByUser($user);
    }

    public function unreadMessages(): int
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return 0;
        }

        return $this->messages->countUnreadFor($user);
    }
}
