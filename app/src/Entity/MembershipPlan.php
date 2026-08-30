<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Type\TranslatedStringType;
use App\Domain\Enum\BillingInterval;
use App\Domain\TranslatedString;
use App\Repository\MembershipPlanRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A purchasable membership tier. Prices are stored in cents to keep money out
 * of floating point; the currency is EUR throughout.
 */
#[ORM\Entity(repositoryClass: MembershipPlanRepository::class)]
#[ORM\Table(name: 'membership_plan')]
#[ORM\UniqueConstraint(name: 'uniq_plan_slug', columns: ['slug'])]
class MembershipPlan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    #[Assert\NotBlank]
    #[Assert\Regex('/^[a-z0-9-]+$/')]
    private string $slug = '';

    #[ORM\Column(type: TranslatedStringType::NAME)]
    private TranslatedString $name;

    #[ORM\Column(type: TranslatedStringType::NAME, nullable: true)]
    private ?TranslatedString $description = null;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $priceCents = 0;

    // "interval" is a reserved word in MySQL, hence the explicit column name.
    #[ORM\Column(name: 'billing_interval', enumType: BillingInterval::class, length: 16)]
    private BillingInterval $billingInterval = BillingInterval::MONTHLY;

    /**
     * Selling points, one entry per bullet, each carrying its own locales.
     *
     * @var list<array<string, string>>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $features = [];

    #[ORM\Column]
    private bool $allBranches = true;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private int $position = 0;

    public function __construct()
    {
        $this->name = new TranslatedString();
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

    public function getName(): TranslatedString
    {
        return $this->name;
    }

    public function setName(TranslatedString $name): static
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

    public function getPriceCents(): int
    {
        return $this->priceCents;
    }

    public function setPriceCents(int $priceCents): static
    {
        $this->priceCents = $priceCents;

        return $this;
    }

    /**
     * Price as a plain decimal string, e.g. "39.90". Display formatting is the
     * job of the template, via the intl currency filter.
     */
    public function getPriceAmount(): string
    {
        return number_format($this->priceCents / 100, 2, '.', '');
    }

    /**
     * What one month of this plan effectively costs, so tiers on different
     * billing intervals can be compared honestly.
     */
    public function getMonthlyEquivalentCents(): int
    {
        return intdiv($this->priceCents, $this->billingInterval->months());
    }

    public function getBillingInterval(): BillingInterval
    {
        return $this->billingInterval;
    }

    public function setBillingInterval(BillingInterval $billingInterval): static
    {
        $this->billingInterval = $billingInterval;

        return $this;
    }

    /**
     * @return list<array<string, string>>
     */
    public function getFeatures(): array
    {
        return $this->features;
    }

    /**
     * @param list<array<string, string>> $features
     */
    public function setFeatures(array $features): static
    {
        $this->features = $features;

        return $this;
    }

    /**
     * Feature bullets resolved into one locale.
     *
     * @return list<string>
     */
    public function getFeatureLines(string $locale): array
    {
        return array_map(
            static fn (array $feature): string => (new TranslatedString($feature))->get($locale),
            $this->features,
        );
    }

    public function isAllBranches(): bool
    {
        return $this->allBranches;
    }

    public function setAllBranches(bool $allBranches): static
    {
        $this->allBranches = $allBranches;

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

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function __toString(): string
    {
        return $this->name->get();
    }
}
