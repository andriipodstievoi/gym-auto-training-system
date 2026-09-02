<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Domain\Enum\ProductKind;
use App\Domain\TranslatedString;
use App\Entity\Product;
use App\Entity\ProductCategory;
use PHPUnit\Framework\TestCase;

/**
 * A shop section and the products filed under it.
 *
 * Product is the owning side of this association, so the category's helpers
 * are the only place both sides get kept in step. Everything below is about
 * that: adding files the product, removing unfiles it, and neither may touch
 * a product that was never ours.
 */
final class ProductCategoryTest extends TestCase
{
    public function testANewSectionIsAnEmptyShelfOfSupplements(): void
    {
        $category = new ProductCategory();

        self::assertSame(ProductKind::SUPPLEMENT, $category->getKind());
        self::assertSame(0, $category->getPosition());
        self::assertCount(0, $category->getProducts());
        self::assertTrue($category->getName()->isEmpty());
        self::assertNull($category->getId());
    }

    public function testAddingAProductSetsBothSidesOfTheRelation(): void
    {
        $category = self::category('protein');
        $product = self::product('whey');

        $category->addProduct($product);

        self::assertTrue($category->getProducts()->contains($product));
        self::assertSame($category, $product->getCategory());
    }

    public function testAProductIsNotFiledTwice(): void
    {
        $category = self::category('protein');
        $product = self::product('whey');

        $category->addProduct($product)->addProduct($product);

        self::assertCount(1, $category->getProducts());
    }

    public function testRemovingAProductClearsItsBackReference(): void
    {
        $category = self::category('protein');
        $product = self::product('whey');

        $category->addProduct($product);
        $category->removeProduct($product);

        self::assertCount(0, $category->getProducts());
        self::assertNull($product->getCategory());
    }

    /**
     * A product filed elsewhere keeps its section: unfiling it here would
     * orphan a row that another category still lists.
     */
    public function testRemovingAProductThatBelongsElsewhereLeavesItsSectionAlone(): void
    {
        $protein = self::category('protein');
        $clothing = self::category('clothing');
        $product = self::product('whey');

        $protein->addProduct($product);
        $clothing->removeProduct($product);

        self::assertSame($protein, $product->getCategory());
        self::assertTrue($protein->getProducts()->contains($product));
    }

    /**
     * Moving a product means unfiling it first. The owning-side setter alone
     * does not touch the old section's collection, which is exactly why the
     * helpers exist.
     */
    public function testMovingAProductBetweenSectionsGoesThroughBothHelpers(): void
    {
        $protein = self::category('protein');
        $creatine = self::category('creatine');
        $product = self::product('whey');

        $protein->addProduct($product);
        $protein->removeProduct($product);
        $creatine->addProduct($product);

        self::assertCount(0, $protein->getProducts());
        self::assertCount(1, $creatine->getProducts());
        self::assertSame($creatine, $product->getCategory());
    }

    public function testASectionPrintsAsItsNameInTheDefaultLocale(): void
    {
        $category = new ProductCategory();
        $category->setName(new TranslatedString(['lv' => 'Proteīns', 'ru' => 'Протеин']));

        // No English, so the Latvian text is what prints.
        self::assertSame('Proteīns', (string) $category);
    }

    public function testTheSettersChainAndStoreWhatTheyWereGiven(): void
    {
        $category = new ProductCategory();
        $name = TranslatedString::of('Training tops');

        $returned = $category->setSlug('training-tops')->setName($name)->setKind(ProductKind::APPAREL)->setPosition(4);

        self::assertSame($category, $returned);
        self::assertSame('training-tops', $category->getSlug());
        self::assertSame($name, $category->getName());
        self::assertSame(ProductKind::APPAREL, $category->getKind());
        self::assertSame(4, $category->getPosition());
    }

    private static function category(string $slug): ProductCategory
    {
        $category = (new ProductCategory())->setSlug($slug);
        $category->setName(TranslatedString::of($slug));

        return $category;
    }

    private static function product(string $slug): Product
    {
        $product = (new Product())->setSlug($slug)->setSku('SPK-'.strtoupper($slug));
        $product->setName(TranslatedString::of($slug));

        return $product;
    }
}
