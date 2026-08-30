<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Type\TranslatedStringType;
use App\Domain\TranslatedString;
use App\Repository\ProductRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Something a member can buy: clothing, supplements, accessories.
 *
 * Variants (size, flavour) arrive with the cart in M4; for now a product is a
 * single sellable line so the catalogue and admin can be built against it.
 */
#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(name: 'product')]
#[ORM\UniqueConstraint(name: 'uniq_product_slug', columns: ['slug'])]
#[ORM\UniqueConstraint(name: 'uniq_product_sku', columns: ['sku'])]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Regex('/^[a-z0-9-]+$/')]
    private string $slug = '';

    #[ORM\Column(length: 32)]
    #[Assert\NotBlank]
    private string $sku = '';

    #[ORM\Column(type: TranslatedStringType::NAME)]
    private TranslatedString $name;

    #[ORM\Column(type: TranslatedStringType::NAME, nullable: true)]
    private ?TranslatedString $description = null;

    #[ORM\ManyToOne(targetEntity: ProductCategory::class, inversedBy: 'products')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ProductCategory $category = null;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $priceCents = 0;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $stock = 0;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imagePath = null;

    #[ORM\Column]
    private bool $active = true;

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

    public function getSku(): string
    {
        return $this->sku;
    }

    public function setSku(string $sku): static
    {
        $this->sku = $sku;

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

    public function getCategory(): ?ProductCategory
    {
        return $this->category;
    }

    public function setCategory(?ProductCategory $category): static
    {
        $this->category = $category;

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

    public function getPriceAmount(): string
    {
        return number_format($this->priceCents / 100, 2, '.', '');
    }

    public function getStock(): int
    {
        return $this->stock;
    }

    public function setStock(int $stock): static
    {
        $this->stock = $stock;

        return $this;
    }

    public function isInStock(): bool
    {
        return $this->stock > 0;
    }

    public function getImagePath(): ?string
    {
        return $this->imagePath;
    }

    public function setImagePath(?string $imagePath): static
    {
        $this->imagePath = $imagePath;

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
