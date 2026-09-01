<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Enum\BookingStatus;
use App\Repository\BookingRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One personal-training session a member asked a coach for.
 *
 * The price is copied in at booking time, exactly as UserMembership and
 * OrderItem copy theirs: raising a coach's hourly rate must never rewrite what
 * somebody was quoted for an hour they already booked.
 *
 * The unique constraint is the real defence against a double booking. Two
 * members can hit "book" in the same second and both pass a PHP check; only
 * one of them can get past the index.
 *
 * It keys on heldSlotAt rather than startsAt, because the two questions are
 * different: startsAt is when the session is, heldSlotAt is whether this row
 * is still holding that hour against everybody else. A cancelled or declined
 * booking sets it to null, and MySQL allows any number of nulls in a unique
 * index - so the hour goes back on sale, which is exactly what SlotFinder
 * already tells members. Keying on startsAt would have let a coach decline an
 * hour that nobody could then re-book.
 */
#[ORM\Entity(repositoryClass: BookingRepository::class)]
#[ORM\Table(name: 'booking')]
#[ORM\UniqueConstraint(name: 'uniq_booking_trainer_held_slot', columns: ['trainer_id', 'held_slot_at'])]
#[ORM\Index(name: 'idx_booking_user_start', columns: ['user_id', 'starts_at'])]
#[ORM\Index(name: 'idx_booking_trainer_status', columns: ['trainer_id', 'status'])]
class Booking
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Trainer::class, inversedBy: 'bookings')]
    #[ORM\JoinColumn(nullable: false)]
    private Trainer $trainer;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'bookings')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column]
    private DateTimeImmutable $startsAt;

    #[ORM\Column]
    private DateTimeImmutable $endsAt;

    #[ORM\Column(enumType: BookingStatus::class, length: 16)]
    private BookingStatus $status = BookingStatus::REQUESTED;

    /**
     * A copy of startsAt while this booking holds the slot, and null once it
     * lets go. Only the unique index reads it; everything else asks the status.
     */
    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $heldSlotAt = null;

    /**
     * The coach's hourly rate at the moment of booking, pro-rated for the
     * length of this session.
     */
    #[ORM\Column]
    private int $pricePaidCents;

    /**
     * What the member wants to work on. Optional - somebody who just wants an
     * hour should not have to write an essay to get one.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000)]
    private ?string $notes = null;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    /**
     * When the coach answered. Null while the request is still open.
     */
    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $respondedAt = null;

    public function __construct(
        Trainer $trainer,
        User $user,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
        ?DateTimeImmutable $now = null,
    ) {
        $this->trainer = $trainer;
        $this->user = $user;
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->createdAt = $now ?? new DateTimeImmutable();
        $this->pricePaidCents = self::proRate($trainer->getHourlyRateCents(), $startsAt, $endsAt);
        $this->syncSlotHold();

        $trainer->addBooking($this);
        $user->addBooking($this);
    }

    /**
     * An hourly rate charged for however long the session actually runs.
     */
    private static function proRate(int $hourlyRateCents, DateTimeImmutable $startsAt, DateTimeImmutable $endsAt): int
    {
        $minutes = max(0, ($endsAt->getTimestamp() - $startsAt->getTimestamp()) / 60);

        return (int) round($hourlyRateCents * $minutes / 60);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTrainer(): Trainer
    {
        return $this->trainer;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getStartsAt(): DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getEndsAt(): DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function getStatus(): BookingStatus
    {
        return $this->status;
    }

    public function setStatus(BookingStatus $status): static
    {
        $this->status = $status;
        $this->syncSlotHold();

        return $this;
    }

    /**
     * Every status change goes through here, so the index can never disagree
     * with the status about whether this hour is spoken for.
     */
    private function syncSlotHold(): void
    {
        $this->heldSlotAt = $this->status->holdsSlot() ? $this->startsAt : null;
    }

    public function getHeldSlotAt(): ?DateTimeImmutable
    {
        return $this->heldSlotAt;
    }

    public function getPricePaidCents(): int
    {
        return $this->pricePaidCents;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $notes = null === $notes ? null : trim($notes);
        $this->notes = ('' === $notes) ? null : $notes;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getRespondedAt(): ?DateTimeImmutable
    {
        return $this->respondedAt;
    }

    public function confirm(?DateTimeImmutable $now = null): static
    {
        $this->status = BookingStatus::CONFIRMED;
        $this->syncSlotHold();
        $this->respondedAt = $now ?? new DateTimeImmutable();

        return $this;
    }

    public function decline(?DateTimeImmutable $now = null): static
    {
        $this->status = BookingStatus::DECLINED;
        $this->syncSlotHold();
        $this->respondedAt = $now ?? new DateTimeImmutable();

        return $this;
    }

    /**
     * Called off before it happened. The slot goes back on sale.
     */
    public function cancel(?DateTimeImmutable $now = null): static
    {
        $this->status = BookingStatus::CANCELLED;
        $this->syncSlotHold();
        $this->respondedAt ??= $now ?? new DateTimeImmutable();

        return $this;
    }

    /**
     * Whether this session is still ahead of us. Says nothing about status: a
     * declined hour tomorrow is upcoming and still not happening.
     */
    public function isUpcoming(?DateTimeImmutable $now = null): bool
    {
        return $this->startsAt > ($now ?? new DateTimeImmutable());
    }

    public function getDurationMinutes(): int
    {
        return (int) round(($this->endsAt->getTimestamp() - $this->startsAt->getTimestamp()) / 60);
    }

    /**
     * Whether the person asking is allowed to call this session off: only the
     * member who booked it, only while it is live, and only before it starts.
     */
    public function isCancellableBy(User $user, ?DateTimeImmutable $now = null): bool
    {
        return $this->user->is($user)
            && $this->status->holdsSlot()
            && $this->isUpcoming($now);
    }

    public function __toString(): string
    {
        return $this->trainer->getFullName().' · '.$this->startsAt->format('Y-m-d H:i');
    }
}
