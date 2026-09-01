<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Enum\OrderStatus;
use App\Repository\OrderRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A shop purchase.
 *
 * The table is customer_order because "order" is a reserved word in MySQL, the
 * same trap MembershipPlan::$interval already fell into.
 *
 * Orders require an account, exactly like memberships: there is a person to
 * ship to and a history to show them. The total is recomputed from the lines
 * and never read from a request, and nothing outside the Stripe webhook may
 * call {@see markPaid()}.
 */
#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: 'customer_order')]
#[ORM\UniqueConstraint(name: 'uniq_order_reference', columns: ['reference'])]
#[ORM\UniqueConstraint(name: 'uniq_order_checkout_session', columns: ['stripe_checkout_session_id'])]
#[ORM\Index(name: 'idx_order_user_status', columns: ['user_id', 'status'])]
class Order
{
    /**
     * No I, O, 0 or 1: the reference gets read down a phone line and dictated
     * across a counter.
     */
    private const string ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'orders')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /**
     * What the member sees. The database id is never shown: it leaks how many
     * orders the shop has taken and is trivial to guess your way along.
     */
    #[ORM\Column(length: 16)]
    private string $reference;

    #[ORM\Column(enumType: OrderStatus::class, length: 16)]
    private OrderStatus $status = OrderStatus::PENDING;

    /**
     * @var Collection<int, OrderItem>
     */
    #[ORM\OneToMany(targetEntity: OrderItem::class, mappedBy: 'order', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $items;

    #[ORM\Column]
    private int $totalCents = 0;

    /**
     * Copied in at purchase, so a member changing their address later does not
     * rewrite where a past receipt was sent.
     */
    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeCheckoutSessionId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripePaymentIntentId = null;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $paidAt = null;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->email = $user->getEmail();
        $this->items = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
        $this->reference = self::generateReference();

        $user->addOrder($this);
    }

    /**
     * A short human-readable order number, generated once and never reused.
     */
    private static function generateReference(): string
    {
        $alphabet = self::ALPHABET;
        $length = strlen($alphabet);
        $code = '';

        foreach (str_split(random_bytes(8)) as $byte) {
            $code .= $alphabet[ord($byte) % $length];
        }

        return 'SPK-'.$code;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getStatus(): OrderStatus
    {
        return $this->status;
    }

    public function setStatus(OrderStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    /**
     * @return Collection<int, OrderItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(OrderItem $item): static
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
        }

        $this->recalculateTotal();

        return $this;
    }

    public function removeItem(OrderItem $item): static
    {
        $this->items->removeElement($item);
        $this->recalculateTotal();

        return $this;
    }

    /**
     * The only thing allowed to write the total. A request may say what it
     * wants in the basket; it never says what that costs.
     */
    public function recalculateTotal(): static
    {
        $total = 0;

        foreach ($this->items as $item) {
            $total += $item->getLineTotalCents();
        }

        $this->totalCents = $total;

        return $this;
    }

    public function getTotalCents(): int
    {
        return $this->totalCents;
    }

    public function getTotalAmount(): string
    {
        return number_format($this->totalCents / 100, 2, '.', '');
    }

    public function getItemCount(): int
    {
        $count = 0;

        foreach ($this->items as $item) {
            $count += $item->getQuantity();
        }

        return $count;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
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

    public function getPaidAt(): ?DateTimeImmutable
    {
        return $this->paidAt;
    }

    /**
     * Record that the money arrived. Only the Stripe webhook calls this -
     * nothing in a browser request may decide an order was paid for.
     */
    public function markPaid(DateTimeImmutable $at): static
    {
        $this->status = OrderStatus::PAID;
        $this->paidAt = $at;

        return $this;
    }

    public function __toString(): string
    {
        return $this->reference.' · '.$this->email;
    }
}
