<?php

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\Type\TranslatedStringType;
use App\Domain\TranslatedString;
use App\Repository\OrderItemRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One line of a shop order, and a snapshot of it.
 *
 * The name, SKU and unit price are copied in rather than read through the
 * product, for the same reason UserMembership copies its price: repricing a
 * product - or deleting it outright - must never rewrite what somebody was
 * charged. The product and variant links survive only for reporting, and both
 * go null rather than taking the receipt with them.
 */
#[ORM\Entity(repositoryClass: OrderItemRepository::class)]
#[ORM\Table(name: 'customer_order_item')]
class OrderItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Product $product = null;

    #[ORM\ManyToOne(targetEntity: ProductVariant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ProductVariant $variant = null;

    #[ORM\Column(type: TranslatedStringType::NAME)]
    private TranslatedString $nameSnapshot;

    #[ORM\Column(length: 32)]
    private string $skuSnapshot = '';

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $unitPriceCents = 0;

    #[ORM\Column]
    #[Assert\Positive]
    private int $quantity = 1;

    public function __construct(Order $order)
    {
        $this->order = $order;
        $this->nameSnapshot = new TranslatedString();

        $order->addItem($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrder(): Order
    {
        return $this->order;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): static
    {
        $this->product = $product;

        return $this;
    }

    public function getVariant(): ?ProductVariant
    {
        return $this->variant;
    }

    public function setVariant(?ProductVariant $variant): static
    {
        $this->variant = $variant;

        return $this;
    }

    public function getNameSnapshot(): TranslatedString
    {
        return $this->nameSnapshot;
    }

    public function setNameSnapshot(TranslatedString $nameSnapshot): static
    {
        $this->nameSnapshot = $nameSnapshot;

        return $this;
    }

    public function getSkuSnapshot(): string
    {
        return $this->skuSnapshot;
    }

    public function setSkuSnapshot(string $skuSnapshot): static
    {
        $this->skuSnapshot = $skuSnapshot;

        return $this;
    }

    public function getUnitPriceCents(): int
    {
        return $this->unitPriceCents;
    }

    public function setUnitPriceCents(int $unitPriceCents): static
    {
        $this->unitPriceCents = $unitPriceCents;

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

    public function getLineTotalCents(): int
    {
        return $this->unitPriceCents * $this->quantity;
    }

    public function getLineTotalAmount(): string
    {
        return number_format($this->getLineTotalCents() / 100, 2, '.', '');
    }

    public function __toString(): string
    {
        return $this->quantity.' × '.$this->nameSnapshot->get();
    }
}
