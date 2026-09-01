<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MessageRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One line in a {@see Conversation}.
 *
 * The sender is a User rather than a "side" flag: a thread has exactly two
 * participants today, and storing who actually typed it survives a coach
 * account changing hands.
 */
#[ORM\Entity(repositoryClass: MessageRepository::class)]
#[ORM\Table(name: 'message')]
#[ORM\Index(name: 'idx_message_conversation_sent', columns: ['conversation_id', 'sent_at'])]
class Message
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Conversation::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Conversation $conversation;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $sender;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 2000)]
    private string $body = '';

    #[ORM\Column]
    private DateTimeImmutable $sentAt;

    /**
     * When the other party opened the thread. Null means unread.
     */
    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $readAt = null;

    public function __construct(Conversation $conversation, User $sender, string $body, ?DateTimeImmutable $now = null)
    {
        $this->conversation = $conversation;
        $this->sender = $sender;
        $this->body = trim($body);
        $this->sentAt = $now ?? new DateTimeImmutable();

        $conversation->addMessage($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConversation(): Conversation
    {
        return $this->conversation;
    }

    public function getSender(): User
    {
        return $this->sender;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): static
    {
        $this->body = trim($body);

        return $this;
    }

    public function getSentAt(): DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function getReadAt(): ?DateTimeImmutable
    {
        return $this->readAt;
    }

    public function isReadYet(): bool
    {
        return null !== $this->readAt;
    }

    public function markRead(?DateTimeImmutable $now = null): static
    {
        $this->readAt ??= $now ?? new DateTimeImmutable();

        return $this;
    }

    public function __toString(): string
    {
        return mb_substr($this->body, 0, 60);
    }
}
