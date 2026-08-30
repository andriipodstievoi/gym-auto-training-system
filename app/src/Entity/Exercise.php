<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Type\TranslatedStringType;
use App\Domain\Enum\EquipmentType;
use App\Domain\Enum\Limitation;
use App\Domain\Enum\MovementPattern;
use App\Domain\Enum\MuscleGroup;
use App\Domain\TranslatedString;
use App\Repository\ExerciseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One movement in the exercise library.
 *
 * This is the table the M6 rule engine selects from: it filters by equipment
 * the member actually has, excludes anything contraindicated by a declared
 * injury, then fills each session by movement pattern and muscle group.
 */
#[ORM\Entity(repositoryClass: ExerciseRepository::class)]
#[ORM\Table(name: 'exercise')]
#[ORM\UniqueConstraint(name: 'uniq_exercise_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_exercise_selection', columns: ['primary_muscle', 'equipment', 'active'])]
class Exercise
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Regex('/^[a-z0-9-]+$/')]
    private string $slug = '';

    #[ORM\Column(type: TranslatedStringType::NAME)]
    private TranslatedString $name;

    #[ORM\Column(type: TranslatedStringType::NAME, nullable: true)]
    private ?TranslatedString $instructions = null;

    #[ORM\Column(enumType: MuscleGroup::class, length: 32)]
    private MuscleGroup $primaryMuscle = MuscleGroup::CHEST;

    /**
     * Backing values of {@see MuscleGroup} that also get meaningful work.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $secondaryMuscles = [];

    #[ORM\Column(enumType: MovementPattern::class, length: 32)]
    private MovementPattern $pattern = MovementPattern::HORIZONTAL_PUSH;

    #[ORM\Column(enumType: EquipmentType::class, length: 32)]
    private EquipmentType $equipment = EquipmentType::BARBELL;

    /**
     * Joints this movement loads heavily. A member who declares the matching
     * limitation never sees the exercise.
     *
     * Backing values of {@see Limitation}.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $contraindications = [];

    /**
     * 1 = anyone can be taught it in a session, 3 = needs real technical base.
     */
    #[ORM\Column(type: Types::SMALLINT)]
    #[Assert\Range(min: 1, max: 3)]
    private int $difficulty = 1;

    #[ORM\Column]
    private bool $active = true;

    public function __construct()
    {
        $this->name = new TranslatedString();
        $this->instructions = new TranslatedString();
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

    public function getName(): TranslatedString
    {
        return $this->name;
    }

    public function setName(TranslatedString $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getInstructions(): ?TranslatedString
    {
        return $this->instructions;
    }

    public function setInstructions(?TranslatedString $instructions): static
    {
        $this->instructions = $instructions;

        return $this;
    }

    public function getPrimaryMuscle(): MuscleGroup
    {
        return $this->primaryMuscle;
    }

    public function setPrimaryMuscle(MuscleGroup $primaryMuscle): static
    {
        $this->primaryMuscle = $primaryMuscle;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getSecondaryMuscles(): array
    {
        return $this->secondaryMuscles;
    }

    /**
     * @param list<string> $secondaryMuscles
     */
    public function setSecondaryMuscles(array $secondaryMuscles): static
    {
        $this->secondaryMuscles = $secondaryMuscles;

        return $this;
    }

    public function getPattern(): MovementPattern
    {
        return $this->pattern;
    }

    public function setPattern(MovementPattern $pattern): static
    {
        $this->pattern = $pattern;

        return $this;
    }

    public function getEquipment(): EquipmentType
    {
        return $this->equipment;
    }

    public function setEquipment(EquipmentType $equipment): static
    {
        $this->equipment = $equipment;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getContraindications(): array
    {
        return $this->contraindications;
    }

    /**
     * @param list<string> $contraindications
     */
    public function setContraindications(array $contraindications): static
    {
        $this->contraindications = $contraindications;

        return $this;
    }

    /**
     * True when any of the member's declared limitations rules this movement out.
     *
     * @param list<Limitation> $limitations
     */
    public function isContraindicatedFor(array $limitations): bool
    {
        foreach ($limitations as $limitation) {
            if (in_array($limitation->value, $this->contraindications, true)) {
                return true;
            }
        }

        return false;
    }

    public function isCompound(): bool
    {
        return $this->pattern->isCompound();
    }

    public function getDifficulty(): int
    {
        return $this->difficulty;
    }

    public function setDifficulty(int $difficulty): static
    {
        $this->difficulty = $difficulty;

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
        return $this->name->get();
    }
}
