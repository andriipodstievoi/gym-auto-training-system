<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Enum\PlanStatus;
use App\Plan\MedicalReferralResult;
use App\Plan\PayloadReader;
use App\Plan\PlanWeek;
use App\Plan\Programme;
use App\Repository\TrainingPlanRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The answer the training-plan service gave, stored so a member can come back
 * to it.
 *
 * The whole response is kept in one JSON column rather than shredded into
 * plan_week / plan_day / plan_exercise tables. That is a deliberate trade: the
 * engine owns the shape of a programme and stamps every response with the
 * engine_version that produced it, so normalising it here would mean a schema
 * migration each time the engine learns a new field - and old rows that no
 * longer round-trip. What is lifted out into real columns is only what this
 * app queries or displays in a list: status, split, engine version, whether the
 * prose layer ran.
 *
 * A medical referral is stored as one of these too, with status
 * MEDICAL_REFERRAL and no weeks. It is the answer to the same question, and a
 * member who was told to see a doctor has to be able to find that answer again
 * rather than wondering whether the site swallowed their questionnaire.
 */
#[ORM\Entity(repositoryClass: TrainingPlanRepository::class)]
#[ORM\Table(name: 'training_plan')]
#[ORM\Index(name: 'idx_training_plan_user_created', columns: ['user_id', 'created_at'])]
class TrainingPlan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * ManyToOne rather than OneToOne on purpose: asking for the same programme
     * twice from one set of answers is a legitimate thing to want, and a unique
     * index would refuse it for no benefit.
     */
    #[ORM\ManyToOne(targetEntity: Assessment::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Assessment $assessment;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(enumType: PlanStatus::class, length: 20)]
    private PlanStatus $status = PlanStatus::OK;

    /**
     * Which set of programming rules produced this. Empty on a referral, which
     * no engine generated.
     */
    #[ORM\Column(length: 32)]
    private string $engineVersion = '';

    /**
     * Whether the prose layer ran. It can only add text - the rule engine
     * decided every set, rep and load either way - but the page says so, and
     * a claim like that has to be backed by a stored fact rather than by
     * whether an API key happened to be set when somebody looked.
     */
    #[ORM\Column]
    private bool $llmUsed = false;

    #[ORM\Column(length: 64)]
    private string $split = '';

    /**
     * The service's whole response, exactly as it came back.
     *
     * @var array<array-key, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    /**
     * The screening questions that stopped this from becoming a programme,
     * named as ai-service/app/schemas.py names them. Empty on a real plan.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $redFlags = [];

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    /**
     * Parsed lazily and kept, because a plan page walks the payload several
     * times and the entity outlives none of those renders.
     */
    private ?Programme $programme = null;

    public function __construct(Assessment $assessment, ?DateTimeImmutable $now = null)
    {
        $this->assessment = $assessment;
        // Never passed in separately: a plan belonging to a different member
        // than the answers it was generated from is not a state worth being
        // able to construct.
        $this->user = $assessment->getUser();
        $this->createdAt = $now ?? new DateTimeImmutable();
    }

    /**
     * A generated programme, from the JSON the service returned.
     *
     * @param array<array-key, mixed> $payload
     */
    public static function fromPayload(Assessment $assessment, array $payload, ?DateTimeImmutable $now = null): self
    {
        $plan = new self($assessment, $now);
        $plan->status = PlanStatus::OK;
        $plan->payload = $payload;
        $plan->engineVersion = mb_substr(PayloadReader::string($payload['engine_version'] ?? null), 0, 32);
        $plan->llmUsed = PayloadReader::bool($payload['llm_used'] ?? null);
        $plan->split = mb_substr(PayloadReader::string($payload['split'] ?? null), 0, 64);

        return $plan;
    }

    /**
     * A referral, from the gate rather than from the generator.
     */
    public static function fromReferral(Assessment $assessment, MedicalReferralResult $referral, ?DateTimeImmutable $now = null): self
    {
        $plan = new self($assessment, $now);
        $plan->status = PlanStatus::MEDICAL_REFERRAL;
        $plan->redFlags = $referral->redFlags;
        $plan->payload = $referral->toPayload();

        return $plan;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAssessment(): Assessment
    {
        return $this->assessment;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getStatus(): PlanStatus
    {
        return $this->status;
    }

    public function isReferral(): bool
    {
        return PlanStatus::MEDICAL_REFERRAL === $this->status;
    }

    public function getEngineVersion(): string
    {
        return $this->engineVersion;
    }

    public function isLlmUsed(): bool
    {
        return $this->llmUsed;
    }

    public function getSplit(): string
    {
        return $this->split;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * @return list<string>
     */
    public function getRedFlags(): array
    {
        return $this->redFlags;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * The payload as objects. Templates go through here rather than through
     * the raw array, so a renamed field breaks in one place instead of in
     * every page that shows a programme.
     */
    public function getProgramme(): Programme
    {
        return $this->programme ??= Programme::fromPayload($this->payload);
    }

    /**
     * @return list<PlanWeek>
     */
    public function getWeeks(): array
    {
        return $this->getProgramme()->weeks;
    }

    public function getWeekCount(): int
    {
        return $this->getProgramme()->getWeekCount();
    }

    public function getDaysPerWeek(): int
    {
        return $this->getProgramme()->getDaysPerWeek();
    }

    public function getCoachingNotes(): string
    {
        return $this->getProgramme()->coachingNotes;
    }

    /**
     * Whether this member may look at this plan.
     *
     * Ownership is asked of the plan rather than written out in each of the
     * two controller actions that need it, because the second one is where it
     * gets forgotten.
     */
    public function isVisibleTo(User $user): bool
    {
        return $this->user->is($user);
    }

    public function __toString(): string
    {
        return ('' === $this->split ? $this->status->value : $this->split)
            .' · '.$this->createdAt->format('Y-m-d');
    }
}
