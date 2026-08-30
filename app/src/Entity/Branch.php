<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Type\TranslatedStringType;
use App\Domain\TranslatedString;
use App\Repository\BranchRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A physical gym location in Riga.
 */
#[ORM\Entity(repositoryClass: BranchRepository::class)]
#[ORM\Table(name: 'branch')]
#[ORM\UniqueConstraint(name: 'uniq_branch_slug', columns: ['slug'])]
class Branch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    #[Assert\NotBlank]
    #[Assert\Regex('/^[a-z0-9-]+$/', message: 'Use lowercase letters, digits and hyphens only.')]
    private string $slug = '';

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    private string $name = '';

    #[ORM\Column(type: TranslatedStringType::NAME, nullable: true)]
    private ?TranslatedString $description = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    private string $addressLine = '';

    #[ORM\Column(length: 80)]
    private string $city = 'Rīga';

    #[ORM\Column(length: 16)]
    private string $postalCode = '';

    #[ORM\Column(type: Types::FLOAT)]
    #[Assert\Range(min: 55.8, max: 57.4, notInRangeMessage: 'Latitude looks outside Latvia.')]
    private float $latitude = 0.0;

    #[ORM\Column(type: Types::FLOAT)]
    #[Assert\Range(min: 20.5, max: 28.5, notInRangeMessage: 'Longitude looks outside Latvia.')]
    private float $longitude = 0.0;

    #[ORM\Column(length: 32)]
    private string $phone = '';

    #[ORM\Column(length: 180)]
    #[Assert\Email]
    private string $email = '';

    /**
     * Opening hours keyed by ISO-8601 weekday number (1 = Monday).
     *
     * @var array<int, array{open: string, close: string}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $openingHours = [];

    #[ORM\Column]
    private bool $active = true;

    /** @var Collection<int, FloorZone> */
    #[ORM\OneToMany(targetEntity: FloorZone::class, mappedBy: 'branch', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $floorZones;

    public function __construct()
    {
        $this->floorZones = new ArrayCollection();
        $this->description = new TranslatedString();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?TranslatedString
    {
        return $this->description;
    }

    public function setDescription(?TranslatedString $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getAddressLine(): string
    {
        return $this->addressLine;
    }

    public function setAddressLine(string $addressLine): static
    {
        $this->addressLine = $addressLine;

        return $this;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function setCity(string $city): static
    {
        $this->city = $city;

        return $this;
    }

    public function getPostalCode(): string
    {
        return $this->postalCode;
    }

    public function setPostalCode(string $postalCode): static
    {
        $this->postalCode = $postalCode;

        return $this;
    }

    public function getLatitude(): float
    {
        return $this->latitude;
    }

    public function setLatitude(float $latitude): static
    {
        $this->latitude = $latitude;

        return $this;
    }

    public function getLongitude(): float
    {
        return $this->longitude;
    }

    public function setLongitude(float $longitude): static
    {
        $this->longitude = $longitude;

        return $this;
    }

    public function getPhone(): string
    {
        return $this->phone;
    }

    public function setPhone(string $phone): static
    {
        $this->phone = $phone;

        return $this;
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

    /**
     * @return array<int, array{open: string, close: string}>
     */
    public function getOpeningHours(): array
    {
        return $this->openingHours;
    }

    /**
     * @param array<int, array{open: string, close: string}> $openingHours
     */
    public function setOpeningHours(array $openingHours): static
    {
        $this->openingHours = $openingHours;

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
     * @return Collection<int, FloorZone>
     */
    public function getFloorZones(): Collection
    {
        return $this->floorZones;
    }

    public function addFloorZone(FloorZone $zone): static
    {
        if (!$this->floorZones->contains($zone)) {
            $this->floorZones->add($zone);
            $zone->setBranch($this);
        }

        return $this;
    }

    public function removeFloorZone(FloorZone $zone): static
    {
        if ($this->floorZones->removeElement($zone) && $zone->getBranch() === $this) {
            $zone->setBranch(null);
        }

        return $this;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
