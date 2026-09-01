<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Enum\Equipment;
use App\Domain\Enum\Experience;
use App\Domain\Enum\Goal;
use App\Domain\Enum\Limitation;
use App\Repository\AssessmentRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A completed questionnaire: what a member told us about themselves.
 *
 * Kept rather than thrown away once a plan exists, for two reasons. A
 * programme can be explained later - "you got this split because you said four
 * days and a full gym" - and the assessment is what gets re-sent when the
 * member asks for the same programme as a PDF, because the service generates
 * from the answers rather than from the plan it already returned.
 *
 * Every numeric range here is the range Pydantic enforces on the other side of
 * the wire. That duplication is deliberate: a value FastAPI would reject with a
 * 422 must never leave this app, because a 422 arriving from a service the
 * member cannot see reads to them as "the site is broken".
 *
 * @see \App\Plan\AssessmentPayload which turns one of these into the wire shape
 */
#[ORM\Entity(repositoryClass: AssessmentRepository::class)]
#[ORM\Table(name: 'assessment')]
#[ORM\Index(name: 'idx_assessment_user_created', columns: ['user_id', 'created_at'])]
class Assessment
{
    /**
     * The eight PAR-Q+ questions, named exactly as the ParQ model in
     * ai-service/app/schemas.py names them.
     *
     * The list exists so the wire payload, the stored red flags, the
     * questionnaire's field names and its translation keys can never disagree
     * about what a question is called - they all read it from here.
     *
     * @var list<string>
     */
    public const array PAR_Q_FIELDS = [
        'heart_condition',
        'chest_pain',
        'dizziness_or_fainting',
        'bone_or_joint_problem',
        'blood_pressure_medication',
        'recent_surgery',
        'pregnancy',
        'other_reason_not_to_exercise',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column]
    #[Assert\Range(min: 14, max: 90)]
    private int $age = 0;

    #[ORM\Column]
    #[Assert\Range(min: 120, max: 230)]
    private int $heightCm = 0;

    #[ORM\Column]
    #[Assert\Range(min: 35, max: 250)]
    private float $weightKg = 0.0;

    #[ORM\Column(enumType: Goal::class, length: 20)]
    private Goal $goal = Goal::GENERAL_FITNESS;

    #[ORM\Column(enumType: Experience::class, length: 20)]
    private Experience $experience = Experience::BEGINNER;

    #[ORM\Column(enumType: Equipment::class, length: 20)]
    private Equipment $equipment = Equipment::FULL_GYM;

    #[ORM\Column]
    #[Assert\Range(min: 2, max: 6)]
    private int $daysPerWeek = 3;

    #[ORM\Column]
    #[Assert\Range(min: 30, max: 120)]
    private int $minutesPerSession = 60;

    /**
     * Stored as the backing values of {@see Limitation}, the same way
     * Trainer::$specialities stores its enum.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $limitations = [];

    /**
     * Free text - movements the member would rather not be given. The engine
     * matches them by name, so they are stored as the member typed them.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $dislikedExercises = [];

    #[ORM\Column]
    private bool $heartCondition = false;

    #[ORM\Column]
    private bool $chestPain = false;

    #[ORM\Column]
    private bool $dizzinessOrFainting = false;

    #[ORM\Column]
    private bool $boneOrJointProblem = false;

    #[ORM\Column]
    private bool $bloodPressureMedication = false;

    #[ORM\Column]
    private bool $recentSurgery = false;

    #[ORM\Column]
    private bool $pregnancy = false;

    #[ORM\Column]
    private bool $otherReasonNotToExercise = false;

    /**
     * The language the member answered in. The engine writes its prose in it,
     * so it travels with the assessment rather than being read off whatever
     * request happens to ask for the PDF months later.
     */
    #[ORM\Column(length: 5)]
    private string $locale = 'en';

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    public function __construct(User $user, ?DateTimeImmutable $now = null)
    {
        $this->user = $user;
        $this->locale = $user->getLocale();
        $this->createdAt = $now ?? new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getAge(): int
    {
        return $this->age;
    }

    public function setAge(int $age): static
    {
        $this->age = $age;

        return $this;
    }

    public function getHeightCm(): int
    {
        return $this->heightCm;
    }

    public function setHeightCm(int $heightCm): static
    {
        $this->heightCm = $heightCm;

        return $this;
    }

    public function getWeightKg(): float
    {
        return $this->weightKg;
    }

    public function setWeightKg(float $weightKg): static
    {
        $this->weightKg = $weightKg;

        return $this;
    }

    public function getGoal(): Goal
    {
        return $this->goal;
    }

    public function setGoal(Goal $goal): static
    {
        $this->goal = $goal;

        return $this;
    }

    public function getExperience(): Experience
    {
        return $this->experience;
    }

    public function setExperience(Experience $experience): static
    {
        $this->experience = $experience;

        return $this;
    }

    public function getEquipment(): Equipment
    {
        return $this->equipment;
    }

    public function setEquipment(Equipment $equipment): static
    {
        $this->equipment = $equipment;

        return $this;
    }

    public function getDaysPerWeek(): int
    {
        return $this->daysPerWeek;
    }

    public function setDaysPerWeek(int $daysPerWeek): static
    {
        $this->daysPerWeek = $daysPerWeek;

        return $this;
    }

    public function getMinutesPerSession(): int
    {
        return $this->minutesPerSession;
    }

    public function setMinutesPerSession(int $minutesPerSession): static
    {
        $this->minutesPerSession = $minutesPerSession;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getLimitations(): array
    {
        return $this->limitations;
    }

    /**
     * @param list<string> $limitations
     */
    public function setLimitations(array $limitations): static
    {
        $this->limitations = $limitations;

        return $this;
    }

    /**
     * The limitations that are still values the engine understands. Anything
     * else in the column is dropped rather than sent on, so a value retired
     * from the enum cannot 422 an old assessment's PDF years later.
     *
     * @return list<Limitation>
     */
    public function getLimitationEnums(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $value): ?Limitation => Limitation::tryFrom($value),
            $this->limitations,
        )));
    }

    /**
     * @return list<string>
     */
    public function getDislikedExercises(): array
    {
        return $this->dislikedExercises;
    }

    /**
     * @param list<string> $dislikedExercises
     */
    public function setDislikedExercises(array $dislikedExercises): static
    {
        $this->dislikedExercises = $dislikedExercises;

        return $this;
    }

    public function isHeartCondition(): bool
    {
        return $this->heartCondition;
    }

    public function setHeartCondition(bool $heartCondition): static
    {
        $this->heartCondition = $heartCondition;

        return $this;
    }

    public function isChestPain(): bool
    {
        return $this->chestPain;
    }

    public function setChestPain(bool $chestPain): static
    {
        $this->chestPain = $chestPain;

        return $this;
    }

    public function isDizzinessOrFainting(): bool
    {
        return $this->dizzinessOrFainting;
    }

    public function setDizzinessOrFainting(bool $dizzinessOrFainting): static
    {
        $this->dizzinessOrFainting = $dizzinessOrFainting;

        return $this;
    }

    public function isBoneOrJointProblem(): bool
    {
        return $this->boneOrJointProblem;
    }

    public function setBoneOrJointProblem(bool $boneOrJointProblem): static
    {
        $this->boneOrJointProblem = $boneOrJointProblem;

        return $this;
    }

    public function isBloodPressureMedication(): bool
    {
        return $this->bloodPressureMedication;
    }

    public function setBloodPressureMedication(bool $bloodPressureMedication): static
    {
        $this->bloodPressureMedication = $bloodPressureMedication;

        return $this;
    }

    public function isRecentSurgery(): bool
    {
        return $this->recentSurgery;
    }

    public function setRecentSurgery(bool $recentSurgery): static
    {
        $this->recentSurgery = $recentSurgery;

        return $this;
    }

    public function isPregnancy(): bool
    {
        return $this->pregnancy;
    }

    public function setPregnancy(bool $pregnancy): static
    {
        $this->pregnancy = $pregnancy;

        return $this;
    }

    public function isOtherReasonNotToExercise(): bool
    {
        return $this->otherReasonNotToExercise;
    }

    public function setOtherReasonNotToExercise(bool $otherReasonNotToExercise): static
    {
        $this->otherReasonNotToExercise = $otherReasonNotToExercise;

        return $this;
    }

    /**
     * The screening answers keyed by the names the contract uses.
     *
     * Written out literally rather than derived from the property names,
     * because these keys are a wire format: renaming a property must not be
     * able to quietly change what FastAPI is sent.
     *
     * @return array{
     *     heart_condition: bool,
     *     chest_pain: bool,
     *     dizziness_or_fainting: bool,
     *     bone_or_joint_problem: bool,
     *     blood_pressure_medication: bool,
     *     recent_surgery: bool,
     *     pregnancy: bool,
     *     other_reason_not_to_exercise: bool,
     * }
     */
    public function getParQ(): array
    {
        return [
            'heart_condition' => $this->heartCondition,
            'chest_pain' => $this->chestPain,
            'dizziness_or_fainting' => $this->dizzinessOrFainting,
            'bone_or_joint_problem' => $this->boneOrJointProblem,
            'blood_pressure_medication' => $this->bloodPressureMedication,
            'recent_surgery' => $this->recentSurgery,
            'pregnancy' => $this->pregnancy,
            'other_reason_not_to_exercise' => $this->otherReasonNotToExercise,
        ];
    }

    /**
     * Sets one screening answer by its contract name. Lets the questionnaire
     * loop over PAR_Q_FIELDS instead of writing the same line eight times with
     * one word different, which is how the eighth one ends up wrong.
     */
    public function setParQAnswer(string $field, bool $answer): static
    {
        return match ($field) {
            'heart_condition' => $this->setHeartCondition($answer),
            'chest_pain' => $this->setChestPain($answer),
            'dizziness_or_fainting' => $this->setDizzinessOrFainting($answer),
            'bone_or_joint_problem' => $this->setBoneOrJointProblem($answer),
            'blood_pressure_medication' => $this->setBloodPressureMedication($answer),
            'recent_surgery' => $this->setRecentSurgery($answer),
            'pregnancy' => $this->setPregnancy($answer),
            'other_reason_not_to_exercise' => $this->setOtherReasonNotToExercise($answer),
            default => $this,
        };
    }

    /**
     * Whether anything here should be looked at by a doctor first.
     *
     * The service applies exactly this gate before it generates anything, and
     * this app applies it before it asks. Duplicating a safety gate is the one
     * kind of duplication worth having: it fails closed on both sides, and it
     * means the answers that raise a flag never cross the wire at all.
     */
    public function hasRedFlags(): bool
    {
        return [] !== $this->getRedFlags();
    }

    /**
     * The screening questions the member answered yes to, named as the
     * contract names them.
     *
     * @return list<string>
     */
    public function getRedFlags(): array
    {
        return array_keys(array_filter($this->getParQ()));
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

    public function __toString(): string
    {
        return $this->user->getEmail().' · '.$this->createdAt->format('Y-m-d H:i');
    }
}
