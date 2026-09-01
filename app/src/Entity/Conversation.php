<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ConversationRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * One thread between a coach and a member.
 *
 * Exactly one per pair - the unique constraint says so - because a member
 * writing to the same coach twice is continuing a conversation, not opening a
 * second one. lastMessageAt is denormalised so the thread list can be sorted
 * without joining every message in the database.
 *
 * The coach side is the Trainer rather than their User: the profile is what a
 * member writes to, and a coach who later gets a different login keeps their
 * history.
 */
#[ORM\Entity(repositoryClass: ConversationRepository::class)]
#[ORM\Table(name: 'conversation')]
#[ORM\UniqueConstraint(name: 'uniq_conversation_pair', columns: ['trainer_id', 'member_id'])]
class Conversation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Trainer::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Trainer $trainer;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'member_id', nullable: false, onDelete: 'CASCADE')]
    private User $member;

    /**
     * @var Collection<int, Message>
     */
    #[ORM\OneToMany(targetEntity: Message::class, mappedBy: 'conversation', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sentAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $messages;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $lastMessageAt;

    public function __construct(Trainer $trainer, User $member, ?DateTimeImmutable $now = null)
    {
        $this->trainer = $trainer;
        $this->member = $member;
        $this->messages = new ArrayCollection();
        $this->createdAt = $now ?? new DateTimeImmutable();
        $this->lastMessageAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTrainer(): Trainer
    {
        return $this->trainer;
    }

    public function getMember(): User
    {
        return $this->member;
    }

    /**
     * @return Collection<int, Message>
     */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function addMessage(Message $message): static
    {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
        }

        $this->lastMessageAt = $message->getSentAt();

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastMessageAt(): DateTimeImmutable
    {
        return $this->lastMessageAt;
    }

    /**
     * The coach's login, if they have one. A coach can be on the public site
     * long before they have an account, so this is legitimately null.
     */
    public function getCoachUser(): ?User
    {
        return $this->trainer->getUser();
    }

    /**
     * Whether this account is one of the two people in the thread. Anyone else
     * has no business reading it.
     */
    public function involves(User $user): bool
    {
        if ($this->member->is($user)) {
            return true;
        }

        $coach = $this->getCoachUser();

        return null !== $coach && $coach->is($user);
    }

    /**
     * The other person, from this account's point of view. Null when the coach
     * side has no login and the member is asking.
     */
    public function counterpartOf(User $user): ?User
    {
        return $this->member->is($user) ? $this->getCoachUser() : $this->member;
    }

    /**
     * The name to head the thread with, which works even when the coach has no
     * account to name.
     */
    public function counterpartNameFor(User $user): string
    {
        return $this->member->is($user)
            ? $this->trainer->getFullName()
            : $this->member->getFullName();
    }

    /**
     * How many messages in this thread this account has not opened yet. Its
     * own messages never count, however long they sit there unread.
     */
    public function unreadCountFor(User $user): int
    {
        $unread = 0;

        foreach ($this->messages as $message) {
            if (!$message->isReadYet() && !$message->getSender()->is($user)) {
                ++$unread;
            }
        }

        return $unread;
    }

    /**
     * Open everything the other party wrote. Returns how many were newly read,
     * so the caller knows whether a flush is worth it.
     */
    public function markReadFor(User $user, ?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable();
        $marked = 0;

        foreach ($this->messages as $message) {
            if (!$message->isReadYet() && !$message->getSender()->is($user)) {
                $message->markRead($now);
                ++$marked;
            }
        }

        return $marked;
    }

    public function __toString(): string
    {
        return $this->trainer->getFullName().' / '.$this->member->getFullName();
    }
}
