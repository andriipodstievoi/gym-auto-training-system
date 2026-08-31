<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Enum\MembershipStatus;
use App\Repository\UserMembershipRepository;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A membership somebody actually holds, as opposed to {@see MembershipPlan},
 * which is the tier on the price list.
 *
 * The price is copied in rather than read through the plan: repricing a tier
 * must not rewrite what past members were charged.
 */
#[ORM\Entity(repositoryClass: UserMembershipRepository::class)]
#[ORM\Table(name: 'user_membership')]
#[ORM\UniqueConstraint(name: 'uniq_membership_checkout_session', columns: ['stripe_checkout_session_id'])]
#[ORM\Index(name: 'idx_membership_user_status', columns: ['user_id', 'status'])]
class UserMembership
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'memberships')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private MembershipPlan $plan;

    #[ORM\Column(enumType: MembershipStatus::class, length: 16)]
    private MembershipStatus $status = MembershipStatus::PENDING;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $startsAt = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $endsAt = null;

    /**
     * What this member was charged, in cents, at the moment of purchase.
     */
    #[ORM\Column]
    private int $pricePaidCents;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeCheckoutSessionId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripePaymentIntentId = null;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    public function __construct(User $user, MembershipPlan $plan)
    {
        $this->user = $user;
        $this->plan = $plan;
        $this->pricePaidCents = $plan->getPriceCents();
        $this->createdAt = new DateTimeImmutable();

        $user->addMembership($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getPlan(): MembershipPlan
    {
        return $this->plan;
    }

    public function getStatus(): MembershipStatus
    {
        return $this->status;
    }

    public function setStatus(MembershipStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getStartsAt(): ?DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getEndsAt(): ?DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function getPricePaidCents(): int
    {
        return $this->pricePaidCents;
    }

    public function getStripeCheckoutSessionId(): ?string
    {
        return $this->stripeCheckoutSessionId;
    }

    public function setStripeCheckoutSessionId(?string $id): static
    {
        $this->stripeCheckoutSessionId = $id;

        return $this;
    }

    public function getStripePaymentIntentId(): ?string
    {
        return $this->stripePaymentIntentId;
    }

    public function setStripePaymentIntentId(?string $id): static
    {
        $this->stripePaymentIntentId = $id;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Start the paid period. Only the Stripe webhook calls this - nothing in a
     * browser request is allowed to decide that money arrived.
     */
    public function activate(DateTimeImmutable $from): static
    {
        $this->status = MembershipStatus::ACTIVE;
        $this->startsAt = $from;
        $this->endsAt = $from->add(new DateInterval('P'.$this->plan->getBillingInterval()->months().'M'));

        return $this;
    }

    /**
     * Stop the renewal. The member keeps their access until the period they
     * already paid for runs out, so {@see getEndsAt()} is left alone.
     */
    public function cancel(): static
    {
        $this->status = MembershipStatus::CANCELLED;

        return $this;
    }

    /**
     * Whether this membership opens the door right now.
     */
    public function isCurrent(?DateTimeImmutable $now = null): bool
    {
        if (!$this->status->grantsAccess()) {
            return false;
        }

        return null === $this->endsAt || $this->endsAt > ($now ?? new DateTimeImmutable());
    }

    public function getDaysRemaining(?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable();

        if (null === $this->endsAt || $this->endsAt <= $now) {
            return 0;
        }

        return (int) $now->diff($this->endsAt)->days;
    }

    public function __toString(): string
    {
        return $this->plan->getName()->get().' · '.$this->user->getEmail();
    }
}
