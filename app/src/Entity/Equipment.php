<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Type\TranslatedStringType;
use App\Domain\Enum\EquipmentType;
use App\Domain\TranslatedString;
use App\Repository\EquipmentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A machine or implement standing in a floor zone.
 */
#[ORM\Entity(repositoryClass: EquipmentRepository::class)]
#[ORM\Table(name: 'equipment')]
class Equipment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: FloorZone::class, inversedBy: 'equipment')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?FloorZone $zone = null;

    #[ORM\Column(type: TranslatedStringType::NAME)]
    private TranslatedString $name;

    #[ORM\Column(enumType: EquipmentType::class, length: 32)]
    private EquipmentType $type = EquipmentType::MACHINE;

    #[ORM\Column]
    #[Assert\Positive]
    private int $quantity = 1;

    public function __construct()
    {
        $this->name = new TranslatedString();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getZone(): ?FloorZone
    {
        return $this->zone;
    }

    public function setZone(?FloorZone $zone): static
    {
        $this->zone = $zone;

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

    public function getType(): EquipmentType
    {
        return $this->type;
    }

    public function setType(EquipmentType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function __toString(): string
    {
        return $this->name->get();
    }
}
