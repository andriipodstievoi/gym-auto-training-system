<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use DateTimeImmutable;
use Deprecated;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use LogicException;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A person with an account: members, coaches and gym staff alike.
 *
 * "user" is a keyword in enough engines to be worth avoiding, hence app_user.
 * Staff are ordinary rows carrying ROLE_ADMIN - there is no second table and
 * no in-memory provider any more.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_user_email', columns: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'register.error.email_taken')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private string $email = '';

    /**
     * Extra roles only. ROLE_USER is implied by {@see getRoles()} and is never
     * stored, so a role can never be lost by editing this column.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    /**
     * The hash, never a plain password.
     */
    #[ORM\Column]
    private string $password = '';

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    private string $firstName = '';

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    private string $lastName = '';

    /**
     * Which language to write to this member in. The site is browsed in three,
     * and email is sent outside the request that chose one.
     */
    #[ORM\Column(length: 5)]
    #[Assert\Choice(choices: ['en', 'lv', 'ru'])]
    private string $locale = 'en';

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    /**
     * @var Collection<int, UserMembership>
     */
    #[ORM\OneToMany(targetEntity: UserMembership::class, mappedBy: 'user', orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $memberships;

    /**
     * @var Collection<int, Order>
     */
    #[ORM\OneToMany(targetEntity: Order::class, mappedBy: 'user', orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $orders;

    /**
     * @var Collection<int, Booking>
     */
    #[ORM\OneToMany(targetEntity: Booking::class, mappedBy: 'user', orphanRemoval: true)]
    #[ORM\OrderBy(['startsAt' => 'DESC'])]
    private Collection $bookings;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->memberships = new ArrayCollection();
        $this->orders = new ArrayCollection();
        $this->bookings = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = mb_strtolower(trim($email));

        return $this;
    }

    /**
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        if ('' === $this->email) {
            throw new LogicException('This user has no email address, so there is nothing to identify it by.');
        }

        return $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = array_values(array_filter($roles, static fn (string $role): bool => 'ROLE_USER' !== $role));

        return $this;
    }

    /**
     * Whether this is the same person as $other.
     *
     * Identity first, then the id: two references to one managed entity are
     * the same object, and comparing ids alone would call two brand-new rows
     * equal because both of their ids are still null.
     */
    public function is(self $other): bool
    {
        if ($this === $other) {
            return true;
        }

        return null !== $this->id && $this->id === $other->getId();
    }

    public function isAdmin(): bool
    {
        return in_array('ROLE_ADMIN', $this->getRoles(), true);
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Nothing to erase: a plain password never reaches this object, the form
     * hands it straight to the hasher.
     */
    #[Deprecated]
    public function eraseCredentials(): void
    {
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = trim($firstName);

        return $this;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = trim($lastName);

        return $this;
    }

    public function getFullName(): string
    {
        return trim($this->firstName.' '.$this->lastName);
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, UserMembership>
     */
    public function getMemberships(): Collection
    {
        return $this->memberships;
    }

    public function addMembership(UserMembership $membership): static
    {
        if (!$this->memberships->contains($membership)) {
            $this->memberships->add($membership);
        }

        return $this;
    }

    /**
     * @return Collection<int, Order>
     */
    public function getOrders(): Collection
    {
        return $this->orders;
    }

    public function addOrder(Order $order): static
    {
        if (!$this->orders->contains($order)) {
            $this->orders->add($order);
        }

        return $this;
    }

    /**
     * @return Collection<int, Booking>
     */
    public function getBookings(): Collection
    {
        return $this->bookings;
    }

    public function addBooking(Booking $booking): static
    {
        if (!$this->bookings->contains($booking)) {
            $this->bookings->add($booking);
        }

        return $this;
    }

    public function __toString(): string
    {
        return '' === $this->getFullName() ? $this->email : $this->getFullName().' <'.$this->email.'>';
    }
}
