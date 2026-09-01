<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Type\TranslatedStringType;
use App\Domain\TranslatedString;
use App\Repository\ProductVariantRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One buyable form of a product: a flavour, a size, a belt width.
 *
 * Variants are optional on purpose - a shaker has none - so the catalogue that
 * existed before the cart keeps working untouched. The price is absolute
 * rather than a delta from the parent, because a large hoodie and a small one
 * do not always differ by a fixed amount, and a repriced variant must not drag
 * every other size with it.
 */
#[ORM\Entity(repositoryClass: ProductVariantRepository::class)]
#[ORM\Table(name: 'product_variant')]
#[ORM\UniqueConstraint(name: 'uniq_variant_sku', columns: ['sku'])]
#[ORM\Index(name: 'idx_variant_product_position', columns: ['product_id', 'position'])]
class ProductVariant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Non-nullable on both sides: a size with no product is not a thing that
     * can exist, so the constructor demands one rather than letting a variant
     * float free until somebody remembers to attach it.
     */
    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'variants')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column(length: 32)]
    #[Assert\NotBlank]
    private string $sku = '';

    #[ORM\Column(type: TranslatedStringType::NAME)]
    private TranslatedString $label;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $priceCents = 0;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $stock = 0;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $active = true;

    public function __construct(Product $product)
    {
        $this->product = $product;
        $this->label = new TranslatedString();

        $product->addVariant($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function setProduct(Product $product): static
    {
        $this->product = $product;

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

    public function getLabel(): TranslatedString
    {
        return $this->label;
    }

    public function setLabel(TranslatedString $label): static
    {
        $this->label = $label;

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

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

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
     * Whether this variant can be put in a basket right now.
     */
    public function isBuyable(): bool
    {
        return $this->active && $this->stock > 0 && $this->product->isActive();
    }

    public function __toString(): string
    {
        $label = $this->label->get();

        return $this->product->getName()->get().' · '.('' === $label ? $this->sku : $label);
    }
}
