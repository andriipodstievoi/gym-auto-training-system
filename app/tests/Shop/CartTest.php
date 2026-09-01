<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use App\Domain\TranslatedString;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Shop\Cart;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * The basket's arithmetic, with a real session and no database.
 */
final class CartTest extends TestCase
{
    public function testANewCartIsEmpty(): void
    {
        $cart = self::cart();

        self::assertTrue($cart->isEmpty());
        self::assertSame(0, $cart->getCount());
        self::assertSame([], $cart->all());
    }

    public function testAddingCountsUnitsRatherThanLines(): void
    {
        $cart = self::cart();
        $product = self::product(1, 900, 150);

        $cart->add($product, null, 2);
        $cart->add(self::product(2, 900, 150), null, 3);

        self::assertFalse($cart->isEmpty());
        self::assertSame(5, $cart->getCount());
        self::assertSame(2, $cart->getLineCount());
        self::assertSame(2, $cart->getQuantity($product, null));
    }

    public function testAddingTheSameLineTwiceAccumulates(): void
    {
        $cart = self::cart();
        $product = self::product(1, 900, 150);

        $cart->add($product, null, 2);
        $cart->add($product, null, 3);

        self::assertSame(5, $cart->getQuantity($product, null));
    }

    public function testAVariantIsATrackedSeparatelyFromItsSiblings(): void
    {
        $cart = self::cart();
        $product = self::product(1, 2200, 60);
        $small = self::variant($product, 10, 'S', 2200, 5);
        $large = self::variant($product, 11, 'L', 2200, 5);

        $cart->add($product, $small, 1);
        $cart->add($product, $large, 2);

        self::assertSame(2, $cart->getLineCount());
        self::assertSame(3, $cart->getCount());
        self::assertSame(['1:10' => 1, '1:11' => 2], $cart->all());
    }

    public function testQuantityIsClampedToStock(): void
    {
        $cart = self::cart();
        $product = self::product(1, 900, 3);

        $cart->add($product, null, 40);

        self::assertSame(3, $cart->getQuantity($product, null));
    }

    public function testQuantityIsClampedToTheSaneMaximum(): void
    {
        $cart = self::cart();
        $product = self::product(1, 900, 100000);

        $cart->add($product, null, 5000);

        self::assertSame(Cart::MAX_QUANTITY, $cart->getQuantity($product, null));
    }

    public function testAProductWithNoStockNeverEntersTheCart(): void
    {
        $cart = self::cart();
        $product = self::product(1, 900, 0);

        $cart->add($product, null, 1);

        self::assertTrue($cart->isEmpty());
    }

    public function testSettingAQuantityToZeroRemovesTheLine(): void
    {
        $cart = self::cart();
        $product = self::product(1, 900, 150);

        $cart->add($product, null, 4);
        $cart->setQuantity($product, null, 0);

        self::assertTrue($cart->isEmpty());
    }

    public function testSettingAQuantityReplacesRatherThanAdds(): void
    {
        $cart = self::cart();
        $product = self::product(1, 900, 150);

        $cart->add($product, null, 4);
        $cart->setQuantity($product, null, 2);

        self::assertSame(2, $cart->getQuantity($product, null));
    }

    public function testRemovingAndClearing(): void
    {
        $cart = self::cart();
        $one = self::product(1, 900, 150);
        $two = self::product(2, 900, 150);

        $cart->add($one, null, 1);
        $cart->add($two, null, 1);
        $cart->remove($one, null);

        self::assertSame(1, $cart->getLineCount());

        $cart->clear();
        self::assertTrue($cart->isEmpty());
    }

    /**
     * The session is client-controlled state, so nothing in it is trusted
     * beyond "these are the shapes I wrote".
     */
    public function testRubbishInTheSessionIsIgnored(): void
    {
        $session = self::session();
        $session->set(Cart::SESSION_KEY, [
            '1:' => 2,
            'not-a-key' => 3,
            '4:5:6' => 1,
            'x:1' => 1,
            '7:' => 'many',
            '8:' => 0,
            '9:' => 500,
        ]);

        $cart = self::cart($session);

        self::assertSame(['1:' => 2, '9:' => Cart::MAX_QUANTITY], $cart->all());
    }

    public function testANonArrayInTheSessionIsIgnored(): void
    {
        $session = self::session();
        $session->set(Cart::SESSION_KEY, 'tampered');

        self::assertSame([], self::cart($session)->all());
    }

    public function testKeysRoundTrip(): void
    {
        self::assertSame('7:', Cart::key(7, null));
        self::assertSame('7:12', Cart::key(7, 12));
        self::assertSame(['product' => 7, 'variant' => null], Cart::parseKey('7:'));
        self::assertSame(['product' => 7, 'variant' => 12], Cart::parseKey('7:12'));
        self::assertNull(Cart::parseKey('nope'));
        self::assertNull(Cart::parseKey(''));
    }

    public function testAvailableStockPrefersTheVariant(): void
    {
        $product = self::product(1, 2200, 60);
        $variant = self::variant($product, 10, 'S', 2200, 4);

        self::assertSame(60, Cart::availableStock($product, null));
        self::assertSame(4, Cart::availableStock($product, $variant));

        $variant->setActive(false);
        self::assertSame(0, Cart::availableStock($product, $variant));
    }

    private static function cart(?Session $session = null): Cart
    {
        $request = new Request();
        $request->setSession($session ?? self::session());

        $stack = new RequestStack();
        $stack->push($request);

        return new Cart($stack);
    }

    private static function session(): Session
    {
        return new Session(new MockArraySessionStorage());
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
     * Ids are Doctrine's to hand out, and the cart keys itself by them, so a
     * database-free test has to fake them.
     */
    private static function setId(object $entity, int $id): void
    {
        $property = new ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);
    }
}
