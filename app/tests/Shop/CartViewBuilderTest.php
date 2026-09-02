<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use App\Domain\TranslatedString;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Repository\ProductRepository;
use App\Repository\ProductVariantRepository;
use App\Shop\Cart;
use App\Shop\CartViewBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Pricing a session against the catalogue, with the catalogue faked out.
 *
 * The session holds ids and quantities and is client-controlled, so this is
 * where a basket is checked against what is actually on the shelf. Two things
 * have to hold every time: nothing that is no longer on sale gets priced, and
 * whatever the builder corrects is written back to the session so the header
 * count and the cart page cannot disagree.
 */
final class CartViewBuilderTest extends TestCase
{
    public function testAnEmptyBasketIsNotWorthAskingTheDatabaseAbout(): void
    {
        $products = $this->createMock(ProductRepository::class);
        $products->expects(self::never())->method('findByIdsIndexed');

        $variants = $this->createMock(ProductVariantRepository::class);
        $variants->expects(self::never())->method('findByIdsIndexed');

        $view = (new CartViewBuilder($products, $variants))->build(self::cart([]));

        self::assertTrue($view->isEmpty());
        self::assertSame(0, $view->getTotalCents());
        self::assertSame(0, $view->getCount());
        self::assertFalse($view->adjusted);
    }

    public function testABareProductIsPricedFromTheProduct(): void
    {
        $product = self::product(1, 900, 50);
        $cart = self::cart(['1:' => 3]);

        $view = self::builder([1 => $product], [])->build($cart);

        self::assertCount(1, $view->lines);
        self::assertFalse($view->adjusted);
        self::assertSame(900, $view->lines[0]->unitPriceCents);
        self::assertSame(3, $view->lines[0]->quantity);
        self::assertSame(2700, $view->getTotalCents());
        self::assertSame(3, $view->getCount());
        self::assertSame('SPK-P-1', $view->lines[0]->getSku());
    }

    /**
     * The variant carries its own absolute price, so a basket must never be
     * charged the parent's.
     */
    public function testAVariantLineIsPricedFromTheVariant(): void
    {
        $product = self::product(1, 2200, 60);
        $large = self::variant($product, 11, 'L', 2600, 5);

        $view = self::builder([1 => $product], [11 => $large])->build(self::cart(['1:11' => 2]));

        self::assertCount(1, $view->lines);
        self::assertSame(2600, $view->lines[0]->unitPriceCents);
        self::assertSame(5200, $view->getTotalCents());
        self::assertSame($large, $view->lines[0]->variant);
        self::assertSame('SPK-V-11', $view->lines[0]->getSku());
    }

    public function testAProductThatHasSinceBeenDeletedDisappearsFromTheBasket(): void
    {
        $cart = self::cart(['1:' => 2]);

        $view = self::builder([], [])->build($cart);

        self::assertTrue($view->isEmpty());
        self::assertTrue($view->adjusted);
        self::assertSame([], $cart->all());
    }

    public function testAProductThatHasBeenSwitchedOffIsNotSold(): void
    {
        $product = self::product(1, 900, 50)->setActive(false);
        $cart = self::cart(['1:' => 2]);

        $view = self::builder([1 => $product], [])->build($cart);

        self::assertTrue($view->isEmpty());
        self::assertTrue($view->adjusted);
        self::assertSame([], $cart->all());
    }

    /**
     * Somebody put the bare product in the basket, then staff gave it sizes.
     * There is no longer an honest price for "one of those", so the line goes
     * rather than guessing which size was meant.
     */
    public function testAProductThatHasGrownVariantsCanNoLongerBeBoughtBare(): void
    {
        $product = self::product(1, 2200, 60);
        self::variant($product, 11, 'L', 2600, 5);

        $cart = self::cart(['1:' => 2]);
        $view = self::builder([1 => $product], [])->build($cart);

        self::assertTrue($view->isEmpty());
        self::assertTrue($view->adjusted);
        self::assertSame([], $cart->all());
    }

    /**
     * The variant id is checked in its own right: a session pointing at a
     * variant that no longer exists must be dropped, not quietly repriced at
     * whatever the parent product costs.
     */
    public function testAVariantThatNoLongerExistsIsDroppedRatherThanFallingBackToTheProduct(): void
    {
        $product = self::product(1, 2200, 60);
        self::variant($product, 11, 'L', 2600, 5);

        $cart = self::cart(['1:99' => 2]);
        $view = self::builder([1 => $product], [])->build($cart);

        self::assertTrue($view->isEmpty());
        self::assertTrue($view->adjusted);
        self::assertSame([], $cart->all());
    }

    public function testAVariantThatHasBeenSwitchedOffIsNotSold(): void
    {
        $product = self::product(1, 2200, 60);
        $large = self::variant($product, 11, 'L', 2600, 5)->setActive(false);

        $cart = self::cart(['1:11' => 2]);
        $view = self::builder([1 => $product], [11 => $large])->build($cart);

        self::assertTrue($view->isEmpty());
        self::assertTrue($view->adjusted);
        self::assertSame([], $cart->all());
    }

    /**
     * A tampered session could pair one product with another product's
     * variant, which would sell the cheap size of an expensive item.
     */
    public function testAVariantBelongingToADifferentProductIsRefused(): void
    {
        $tee = self::product(1, 2200, 60);
        self::variant($tee, 11, 'L', 2600, 5);

        $hoodie = self::product(2, 5900, 20);
        $hoodieLarge = self::variant($hoodie, 21, 'L', 5900, 5);

        $cart = self::cart(['1:21' => 1]);
        $view = self::builder([1 => $tee], [21 => $hoodieLarge])->build($cart);

        self::assertTrue($view->isEmpty());
        self::assertTrue($view->adjusted);
        self::assertSame([], $cart->all());
    }

    public function testAQuantityAboveWhatIsLeftOnTheShelfIsClampedDownToIt(): void
    {
        $product = self::product(1, 900, 2);
        $cart = self::cart(['1:' => 5]);

        $view = self::builder([1 => $product], [])->build($cart);

        self::assertTrue($view->adjusted);
        self::assertSame(2, $view->lines[0]->quantity);
        self::assertSame(2, $view->lines[0]->availableStock);
        self::assertSame(1800, $view->getTotalCents());

        // Written back, so the header badge agrees with the page.
        self::assertSame(['1:' => 2], $cart->all());
    }

    /**
     * A typed-in 4000 must not overflow a total. The cap is applied as the
     * session is read, so the builder never sees the larger number and has
     * nothing to correct - but what gets priced is still the cap.
     */
    public function testAQuantityIsNeverPricedAboveTheSaneMaximum(): void
    {
        $product = self::product(1, 100, 5000);
        $cart = self::cart(['1:' => 4000]);

        $view = self::builder([1 => $product], [])->build($cart);

        self::assertSame(Cart::MAX_QUANTITY, $view->lines[0]->quantity);
        self::assertSame(Cart::MAX_QUANTITY * 100, $view->getTotalCents());
        self::assertSame(['1:' => Cart::MAX_QUANTITY], $cart->all());
    }

    public function testALineWithNothingLeftInStockIsRemovedRatherThanShownAtZero(): void
    {
        $product = self::product(1, 900, 0);
        $cart = self::cart(['1:' => 2]);

        $view = self::builder([1 => $product], [])->build($cart);

        self::assertTrue($view->isEmpty());
        self::assertTrue($view->adjusted);
        self::assertSame([], $cart->all());
    }

    /**
     * A sold-out size sits alongside sizes that are still there, so dropping
     * one line must leave the rest of the basket priced and intact.
     */
    public function testDroppingOneLineLeavesTheRestOfTheBasketAlone(): void
    {
        $product = self::product(1, 2200, 60);
        $small = self::variant($product, 10, 'S', 1900, 4);
        $large = self::variant($product, 11, 'L', 2600, 0);

        $cart = self::cart(['1:10' => 2, '1:11' => 1]);
        $view = self::builder([1 => $product], [10 => $small, 11 => $large])->build($cart);

        self::assertCount(1, $view->lines);
        self::assertTrue($view->adjusted);
        self::assertSame('1:10', $view->lines[0]->key);
        self::assertSame(3800, $view->getTotalCents());
        self::assertSame(['1:10' => 2], $cart->all());
    }

    /**
     * Two sizes of one shirt are one product, and the whole point of the
     * indexed lookups is that they cost one query each rather than one per
     * line.
     */
    public function testTheCatalogueIsAskedForEachIdOnceRegardlessOfHowManyLinesUseIt(): void
    {
        $product = self::product(1, 2200, 60);
        $small = self::variant($product, 10, 'S', 1900, 4);
        $large = self::variant($product, 11, 'L', 2600, 4);

        $products = $this->createMock(ProductRepository::class);
        $products->expects(self::once())->method('findByIdsIndexed')->with([1])->willReturn([1 => $product]);

        $variants = $this->createMock(ProductVariantRepository::class);
        $variants->expects(self::once())
            ->method('findByIdsIndexed')
            ->with([10, 11])
            ->willReturn([10 => $small, 11 => $large]);

        $view = (new CartViewBuilder($products, $variants))->build(self::cart(['1:10' => 1, '1:11' => 2]));

        self::assertCount(2, $view->lines);
        self::assertSame(3, $view->getCount());
        self::assertFalse($view->adjusted);
    }

    /**
     * A price change between adding and paying is picked up on the next
     * render, because the line is built from the entity rather than from
     * whatever the session last saw.
     */
    public function testEveryRenderPricesAgainstTheCatalogueRatherThanTheSession(): void
    {
        $product = self::product(1, 900, 50);
        $cart = self::cart(['1:' => 2]);
        $builder = self::builder([1 => $product], []);

        self::assertSame(1800, $builder->build($cart)->getTotalCents());

        $product->setPriceCents(1200);

        self::assertSame(2400, $builder->build($cart)->getTotalCents());
    }

    public function testTheLineNameCarriesTheVariantLabel(): void
    {
        $product = self::product(1, 2200, 60);
        $large = self::variant($product, 11, 'L', 2600, 5);

        $view = self::builder([1 => $product], [11 => $large])->build(self::cart(['1:11' => 1]));

        self::assertSame('Product 1 · L', $view->lines[0]->getName()->get('en'));
    }

    /**
     * @param array<int, Product>        $products
     * @param array<int, ProductVariant> $variants
     */
    private static function builder(array $products, array $variants): CartViewBuilder
    {
        $productRepository = self::createStub(ProductRepository::class);
        $productRepository->method('findByIdsIndexed')->willReturn($products);

        $variantRepository = self::createStub(ProductVariantRepository::class);
        $variantRepository->method('findByIdsIndexed')->willReturn($variants);

        return new CartViewBuilder($productRepository, $variantRepository);
    }

    /**
     * A cart seeded straight into the session, so a quantity the cart itself
     * would have clamped on the way in can still be found on the way out.
     *
     * @param array<string, int> $lines
     */
    private static function cart(array $lines): Cart
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set(Cart::SESSION_KEY, $lines);

        $request = new Request();
        $request->setSession($session);

        $stack = new RequestStack();
        $stack->push($request);

        return new Cart($stack);
    }

    private static function product(int $id, int $priceCents, int $stock): Product
    {
        $product = (new Product())
            ->setSlug('product-'.$id)
            ->setSku('SPK-P-'.$id)
            ->setPriceCents($priceCents)
            ->setStock($stock);
        $product->setName(TranslatedString::of('Product '.$id));

        self::setId($product, $id);

        return $product;
    }

    private static function variant(Product $product, int $id, string $label, int $priceCents, int $stock): ProductVariant
    {
        $variant = (new ProductVariant($product))
            ->setSku('SPK-V-'.$id)
            ->setLabel(TranslatedString::of($label))
            ->setPriceCents($priceCents)
            ->setStock($stock);

        self::setId($variant, $id);

        return $variant;
    }

    /**
     * Ids are Doctrine's to hand out, and both the cart keys and the
     * variant-belongs-to-product check are keyed on them.
     */
    private static function setId(object $entity, int $id): void
    {
        $property = new ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);
    }
}
