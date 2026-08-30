<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Type\TranslatedStringType;
use App\Domain\Enum\ProductKind;
use App\Domain\TranslatedString;
use App\Repository\ProductCategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A shop section, e.g. protein, creatine, training tops.
 */
#[ORM\Entity(repositoryClass: ProductCategoryRepository::class)]
#[ORM\Table(name: 'product_category')]
#[ORM\UniqueConstraint(name: 'uniq_category_slug', columns: ['slug'])]
class ProductCategory
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

    #[ORM\Column(enumType: ProductKind::class, length: 32)]
    private ProductKind $kind = ProductKind::SUPPLEMENT;

    #[ORM\Column]
    private int $position = 0;

    /** @var Collection<int, Product> */
    #[ORM\OneToMany(targetEntity: Product::class, mappedBy: 'category')]
    private Collection $products;

    public function __construct()
    {
        $this->products = new ArrayCollection();
        $this->name = new TranslatedString();
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

    public function getKind(): ProductKind
    {
        return $this->kind;
    }

    public function setKind(ProductKind $kind): static
    {
        $this->kind = $kind;

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
     * @return Collection<int, Product>
     */
    public function getProducts(): Collection
    {
        return $this->products;
    }

    public function addProduct(Product $product): static
    {
        if (!$this->products->contains($product)) {
            $this->products->add($product);
            $product->setCategory($this);
        }

        return $this;
    }

    public function removeProduct(Product $product): static
    {
        if ($this->products->removeElement($product) && $product->getCategory() === $this) {
            $product->setCategory(null);
        }

        return $this;
    }

    public function __toString(): string
    {
        return $this->name->get();
    }
}
