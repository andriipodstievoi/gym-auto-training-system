<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Domain\TranslatedString;
use App\Entity\Product;
use App\Entity\ProductVariant;
use PHPUnit\Framework\TestCase;

/**
 * How a product prices itself once it grows sizes.
 */
final class ProductVariantTest extends TestCase
{
    public function testAProductWithoutVariantsPricesItself(): void
    {
        $product = self::product(2200, 60);

        self::assertFalse($product->hasVariants());
        self::assertSame(2200, $product->getPriceCentsFrom());
        self::assertSame(60, $product->getTotalStock());
        self::assertSame([], $product->getAvailableVariants());
    }

    public function testTheAdvertisedPriceIsTheCheapestActiveVariant(): void
    {
        $product = self::product(2200, 60);
        self::variant($product, 'M', 2400, 5);
        self::variant($product, 'S', 1900, 3);

        self::assertTrue($product->hasVariants());
        self::assertSame(1900, $product->getPriceCentsFrom());
    }

    public function testADeactivatedVariantIsNeitherPricedNorCounted(): void
    {
        $product = self::product(2200, 60);
        self::variant($product, 'M', 2400, 5);
        self::variant($product, 'S', 1000, 3)->setActive(false);

        self::assertSame(2400, $product->getPriceCentsFrom());
        self::assertSame(5, $product->getTotalStock());
        self::assertCount(1, $product->getAvailableVariants());
    }

    public function testTotalStockAddsUpTheActiveVariants(): void
    {
        $product = self::product(2200, 999);
        self::variant($product, 'S', 2200, 4);
        self::variant($product, 'M', 2200, 6);

        // The product's own stock column is ignored once variants exist.
        self::assertSame(10, $product->getTotalStock());
    }

    public function testAnEmptyVariantIsNotOffered(): void
    {
        $product = self::product(2200, 60);
        self::variant($product, 'S', 2200, 0);
        self::variant($product, 'M', 2200, 2);

        $available = $product->getAvailableVariants();

        self::assertCount(1, $available);
        self::assertSame('M', $available[0]->getLabel()->get('en'));

        // Sold out is still a real price to advertise from.
        self::assertSame(2200, $product->getPriceCentsFrom());
    }

    public function testAddingAVariantSetsBothSidesOfTheRelation(): void
    {
        $product = self::product(2200, 60);
        $variant = self::variant($product, 'L', 2200, 1);

        self::assertSame($product, $variant->getProduct());
        self::assertTrue($product->getVariants()->contains($variant));

        $product->removeVariant($variant);
        self::assertFalse($product->getVariants()->contains($variant));
    }

    public function testAVariantIsOnlyBuyableWhileEverythingAboveItIs(): void
    {
        $product = self::product(2200, 60);
        $variant = self::variant($product, 'L', 2200, 2);

        self::assertTrue($variant->isBuyable());

        $product->setActive(false);
        self::assertFalse($variant->isBuyable());

        $product->setActive(true);
        $variant->setStock(0);
        self::assertFalse($variant->isBuyable());
    }

    public function testTheVariantPriceIsAbsoluteRatherThanADelta(): void
    {
        $product = self::product(2200, 60);
        $variant = self::variant($product, 'XL', 2600, 1);

        // Repricing the parent leaves the variant exactly where it was.
        $product->setPriceCents(9900);

        self::assertSame(2600, $variant->getPriceCents());
        self::assertSame('26.00', $variant->getPriceAmount());
        self::assertSame(2600, $product->getPriceCentsFrom());
    }

    private static function product(int $priceCents, int $stock): Product
    {
        $product = (new Product())
            ->setSlug('training-tee')
            ->setSku('SPK-TEE-BLK')
            ->setPriceCents($priceCents)
            ->setStock($stock);

        return $product->setName(TranslatedString::of('Training tee'));
    }

    private static function variant(Product $product, string $label, int $priceCents, int $stock): ProductVariant
    {
        return (new ProductVariant($product))
            ->setSku('SPK-TEE-BLK-'.$label)
            ->setLabel(TranslatedString::of($label))
            ->setPriceCents($priceCents)
            ->setStock($stock);
    }
}
