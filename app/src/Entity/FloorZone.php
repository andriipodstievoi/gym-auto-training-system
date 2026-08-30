<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Type\TranslatedStringType;
use App\Domain\TranslatedString;
use App\Repository\FloorZoneRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A training area inside a branch - free weights, machines, cardio, studio.
 *
 * {@see $svgId} ties the record to a path in the branch floor-plan SVG, which
 * is what makes the plan clickable in M2.
 */
#[ORM\Entity(repositoryClass: FloorZoneRepository::class)]
#[ORM\Table(name: 'floor_zone')]
#[ORM\UniqueConstraint(name: 'uniq_zone_branch_svg', columns: ['branch_id', 'svg_id'])]
class FloorZone
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Branch::class, inversedBy: 'floorZones')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Branch $branch = null;

    /**
     * The id of the corresponding <path> or <g> in the floor-plan SVG.
     */
    #[ORM\Column(length: 64)]
    #[Assert\NotBlank]
    #[Assert\Regex('/^[a-z0-9-]+$/')]
    private string $svgId = '';

    #[ORM\Column(type: TranslatedStringType::NAME)]
    private TranslatedString $name;

    #[ORM\Column(type: TranslatedStringType::NAME, nullable: true)]
    private ?TranslatedString $description = null;

    #[ORM\Column]
    private int $position = 0;

    /** @var Collection<int, Equipment> */
    #[ORM\OneToMany(targetEntity: Equipment::class, mappedBy: 'zone', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $equipment;

    public function __construct()
    {
        $this->equipment = new ArrayCollection();
        $this->name = new TranslatedString();
        $this->description = new TranslatedString();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getSvgId(): string
    {
        return $this->svgId;
    }

    public function setSvgId(string $svgId): static
    {
        $this->svgId = $svgId;

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

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    /**
     * @return Collection<int, Equipment>
     */
    public function getEquipment(): Collection
    {
        return $this->equipment;
    }

    public function addEquipment(Equipment $equipment): static
    {
        if (!$this->equipment->contains($equipment)) {
            $this->equipment->add($equipment);
            $equipment->setZone($this);
        }

        return $this;
    }

    public function removeEquipment(Equipment $equipment): static
    {
        if ($this->equipment->removeElement($equipment) && $equipment->getZone() === $this) {
            $equipment->setZone(null);
        }

        return $this;
    }

    public function __toString(): string
    {
        return $this->name->get();
    }
}
