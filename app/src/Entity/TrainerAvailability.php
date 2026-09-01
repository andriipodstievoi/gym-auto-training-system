<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TrainerAvailabilityRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One weekly window a coach works, for example "Tuesdays 09:00 to 13:00".
 *
 * Recurring rather than dated on purpose: a coach types their week once and it
 * keeps generating slots, instead of somebody re-entering the same hours every
 * Sunday. {@see \App\Booking\SlotFinder} expands these into concrete hours.
 *
 * The weekday is ISO-8601 - 1 is Monday, 7 is Sunday - so it lines up with
 * DateTimeInterface::format('N') and with the weekday.1..weekday.7 keys.
 */
#[ORM\Entity(repositoryClass: TrainerAvailabilityRepository::class)]
#[ORM\Table(name: 'trainer_availability')]
#[ORM\Index(name: 'idx_availability_trainer_weekday', columns: ['trainer_id', 'weekday'])]
class TrainerAvailability
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Trainer::class, inversedBy: 'availability')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Trainer $trainer;

    #[ORM\Column(type: Types::SMALLINT)]
    #[Assert\Range(min: 1, max: 7)]
    private int $weekday = 1;

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    #[Assert\NotNull]
    private DateTimeImmutable $startTime;

    /**
     * Exclusive: a window ending at 13:00 offers no slot starting at 13:00.
     */
    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    #[Assert\NotNull]
    #[Assert\GreaterThan(propertyPath: 'startTime')]
    private DateTimeImmutable $endTime;

    /**
     * Switched off rather than deleted, so a coach can pause a morning without
     * losing it - and so past bookings keep the window that produced them.
     */
    #[ORM\Column]
    private bool $active = true;

    /**
     * The coach is optional here only so the back office can new one up before
     * the form has picked a trainer; the column itself is NOT NULL.
     */
    public function __construct(?Trainer $trainer = null)
    {
        $this->startTime = new DateTimeImmutable('09:00');
        $this->endTime = new DateTimeImmutable('17:00');

        if (null !== $trainer) {
            $this->setTrainer($trainer);
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTrainer(): Trainer
    {
        return $this->trainer;
    }

    public function setTrainer(Trainer $trainer): static
    {
        $this->trainer = $trainer;
        $trainer->addAvailability($this);

        return $this;
    }

    public function getWeekday(): int
    {
        return $this->weekday;
    }

    public function setWeekday(int $weekday): static
    {
        $this->weekday = $weekday;

        return $this;
    }

    public function getStartTime(): DateTimeImmutable
    {
        return $this->startTime;
    }

    public function setStartTime(DateTimeImmutable $startTime): static
    {
        $this->startTime = $startTime;

        return $this;
    }

    public function getEndTime(): DateTimeImmutable
    {
        return $this->endTime;
    }

    public function setEndTime(DateTimeImmutable $endTime): static
    {
        $this->endTime = $endTime;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    /**
     * Minutes between the two ends of the window.
     */
    public function getLengthMinutes(): int
    {
        $start = (int) $this->startTime->format('H') * 60 + (int) $this->startTime->format('i');
        $end = (int) $this->endTime->format('H') * 60 + (int) $this->endTime->format('i');

        return max(0, $end - $start);
    }

    public function __toString(): string
    {
        return sprintf(
            '%s %s-%s',
            $this->trainer->getFullName(),
            $this->startTime->format('H:i'),
            $this->endTime->format('H:i'),
        );
    }
}
