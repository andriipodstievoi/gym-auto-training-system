<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Type\TranslatedStringType;
use App\Domain\Enum\TrainerSpeciality;
use App\Domain\TranslatedString;
use App\Repository\TrainerRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A coach members can browse and, from M5, book.
 *
 * Deliberately not tied to a User account yet - accounts arrive in M3, and a
 * nullable user relation can be added then without reshaping this table.
 */
#[ORM\Entity(repositoryClass: TrainerRepository::class)]
#[ORM\Table(name: 'trainer')]
#[ORM\UniqueConstraint(name: 'uniq_trainer_slug', columns: ['slug'])]
class Trainer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    #[Assert\NotBlank]
    #[Assert\Regex('/^[a-z0-9-]+$/')]
    private string $slug = '';

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    private string $fullName = '';

    #[ORM\Column(type: TranslatedStringType::NAME, nullable: true)]
    private ?TranslatedString $bio = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photoPath = null;

    /**
     * Stored as the backing values of {@see TrainerSpeciality}.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $specialities = [];

    /**
     * Locale codes the trainer can coach in, for example lv and ru.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $languages = [];

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $hourlyRateCents = 0;

    #[ORM\ManyToOne(targetEntity: Branch::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Branch $branch = null;

    #[ORM\Column]
    private bool $active = true;

    public function __construct()
    {
        $this->bio = new TranslatedString();
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

    public function getFullName(): string
    {
        return $this->fullName;
    }

    public function setFullName(string $fullName): static
    {
        $this->fullName = $fullName;

        return $this;
    }

    public function getBio(): ?TranslatedString
    {
        return $this->bio;
    }

    public function setBio(?TranslatedString $bio): static
    {
        $this->bio = $bio;

        return $this;
    }

    public function getPhotoPath(): ?string
    {
        return $this->photoPath;
    }

    public function setPhotoPath(?string $photoPath): static
    {
        $this->photoPath = $photoPath;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getSpecialities(): array
    {
        return $this->specialities;
    }

    /**
     * @param list<string> $specialities
     */
    public function setSpecialities(array $specialities): static
    {
        $this->specialities = $specialities;

        return $this;
    }

    /**
     * @return list<TrainerSpeciality>
     */
    public function getSpecialityEnums(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $value): ?TrainerSpeciality => TrainerSpeciality::tryFrom($value),
            $this->specialities,
        )));
    }

    /**
     * @return list<string>
     */
    public function getLanguages(): array
    {
        return $this->languages;
    }

    /**
     * @param list<string> $languages
     */
    public function setLanguages(array $languages): static
    {
        $this->languages = $languages;

        return $this;
    }

    public function speaks(string $locale): bool
    {
        return in_array($locale, $this->languages, true);
    }

    public function getHourlyRateCents(): int
    {
        return $this->hourlyRateCents;
    }

    public function setHourlyRateCents(int $hourlyRateCents): static
    {
        $this->hourlyRateCents = $hourlyRateCents;

        return $this;
    }

    public function getBranch(): ?Branch
    {
        return $this->branch;
    }

    public function setBranch(?Branch $branch): static
    {
        $this->branch = $branch;

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

    public function __toString(): string
    {
        return $this->fullName;
    }
}
