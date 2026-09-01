<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Product;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The basket, signed out, against a seeded test database.
 *
 * The point of most of these is that the session is trusted for identifiers
 * and nothing else: every price and every stock level on the cart page has
 * been re-read from the database on the way in.
 */
final class CartControllerTest extends WebTestCase
{
    /**
     * What csrf_token('cart') actually renders - see CheckoutControllerTest.
     */
    private const string CSRF_TOKEN = 'csrf-token';

    protected function tearDown(): void
    {
        self::restoreShaker();
        parent::tearDown();
    }

    public function testAnEmptyCartSaysSo(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/en/cart');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('table'));
    }

    public function testAddingAProductPutsAPricedLineInTheCart(): void
    {
        $client = static::createClient();
        $crawler = self::addToCart($client, 'shaker', 2);

        // 2 x 9.00 EUR, straight from the fixtures.
        $text = $crawler->filter('body')->text();
        self::assertStringContainsString('Shaker, 700 ml', $text);
        self::assertStringContainsString('18.00', $text);
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertSame('2', $crawler->filter('tbody input[name="quantity"]')->attr('value'));
    }

    public function testAddingAVariantPricesTheVariantNotTheProduct(): void
    {
        $client = static::createClient();
        $client->request('GET', '/en/shop/p/whey-vanilla');

        $variantId = self::variantId('whey-vanilla', 'SPK-WHEY-VAN-VCN');

        $client->request('POST', '/en/cart/add', [
            '_token' => self::CSRF_TOKEN,
            'slug' => 'whey-vanilla',
            'variant' => (string) $variantId,
            'quantity' => '1',
        ]);

        self::assertResponseRedirects('/en/cart');
        $crawler = $client->followRedirect();

        $text = $crawler->filter('body')->text();
        self::assertStringContainsString('Vanilla and cinnamon', $text);
        self::assertStringContainsString('31.90', $text);
        self::assertStringContainsString('SPK-WHEY-VAN-VCN', $text);
    }

    public function testUpdatingAQuantityChangesTheTotal(): void
    {
        $client = static::createClient();
        self::addToCart($client, 'shaker', 1);

        $client->request('POST', '/en/cart/update', [
            '_token' => self::CSRF_TOKEN,
            'key' => self::cartKey('shaker'),
            'quantity' => '3',
        ]);

        self::assertResponseRedirects('/en/cart');
        $crawler = $client->followRedirect();

        self::assertStringContainsString('27.00', $crawler->filter('body')->text());
    }

    public function testRemovingALineEmptiesTheCart(): void
    {
        $client = static::createClient();
        self::addToCart($client, 'shaker', 1);

        $client->request('POST', '/en/cart/remove', [
            '_token' => self::CSRF_TOKEN,
            'key' => self::cartKey('shaker'),
        ]);

        self::assertResponseRedirects('/en/cart');
        $crawler = $client->followRedirect();

        self::assertCount(0, $crawler->filter('table'));
    }

    /**
     * The session holds ids, never prices. Repricing between adding and
     * looking must show up immediately.
     */
    public function testThePriceIsReReadFromTheDatabaseOnEveryRender(): void
    {
        $client = static::createClient();
        $crawler = self::addToCart($client, 'shaker', 1);
        self::assertStringContainsString('9.00', $crawler->filter('body')->text());

        self::repriceShaker(1250);

        $crawler = $client->request('GET', '/en/cart');
        $text = $crawler->filter('body')->text();

        self::assertStringContainsString('12.50', $text);
        self::assertStringNotContainsString('9.00', $text);
    }

    public function testALineWhoseProductWasDeactivatedDisappears(): void
    {
        $client = static::createClient();
        self::addToCart($client, 'shaker', 1);

        self::setShakerActive(false);

        $crawler = $client->request('GET', '/en/cart');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('table'));
    }

    public function testQuantityIsClampedToWhatIsInStock(): void
    {
        self::restockShaker(3);

        $client = static::createClient();
        $crawler = self::addToCart($client, 'shaker', 40);

        self::assertSame('3', $crawler->filter('tbody input[name="quantity"]')->attr('value'));
        self::assertStringContainsString('27.00', $crawler->filter('body')->text());
    }

    public function testAProductWithNoStockCannotBeAdded(): void
    {
        self::restockShaker(0);

        $client = static::createClient();
        $client->request('GET', '/en/shop/p/shaker');
        $client->request('POST', '/en/cart/add', [
            '_token' => self::CSRF_TOKEN,
            'slug' => 'shaker',
            'quantity' => '1',
        ]);

        self::assertResponseRedirects('/en/shop/p/shaker');

        $crawler = $client->request('GET', '/en/cart');
        self::assertCount(0, $crawler->filter('table'));
    }

    public function testAPostWithABadTokenIsRejectedAndChangesNothing(): void
    {
        $client = static::createClient();
        self::addToCart($client, 'shaker', 1);

        $client->request('POST', '/en/cart/update', [
            '_token' => 'not-the-token',
            'key' => self::cartKey('shaker'),
            'quantity' => '7',
        ]);

        self::assertResponseRedirects('/en/cart');
        $crawler = $client->followRedirect();

        // Still one, and still 9.00.
        self::assertSame('1', $crawler->filter('tbody input[name="quantity"]')->attr('value'));
        self::assertStringContainsString('9.00', $crawler->filter('body')->text());
    }

    public function testAddingWithABadTokenAddsNothing(): void
    {
        $client = static::createClient();
        $client->request('GET', '/en/shop/p/shaker');

        $client->request('POST', '/en/cart/add', [
            '_token' => 'not-the-token',
            'slug' => 'shaker',
            'quantity' => '1',
        ]);

        self::assertResponseRedirects('/en/cart');
        $crawler = $client->followRedirect();

        self::assertCount(0, $crawler->filter('table'));
    }

    /**
     * A synthesised POST needs a GET before it, so BrowserKit has a Referer
     * for the stateless CSRF check to validate.
     */
    private static function addToCart(KernelBrowser $client, string $slug, int $quantity): \Symfony\Component\DomCrawler\Crawler
    {
        $client->request('GET', '/en/shop/p/'.$slug);
        $client->request('POST', '/en/cart/add', [
            '_token' => self::CSRF_TOKEN,
            'slug' => $slug,
            'quantity' => (string) $quantity,
        ]);

        self::assertResponseRedirects('/en/cart');

        return $client->followRedirect();
    }

    private static function cartKey(string $slug): string
    {
        return self::product($slug)->getId().':';
    }

    private static function variantId(string $slug, string $sku): int
    {
        foreach (self::product($slug)->getVariants() as $variant) {
            if ($variant->getSku() === $sku) {
                $id = $variant->getId();
                self::assertIsInt($id);

                return $id;
            }
        }

        self::fail(\sprintf('No variant "%s" on product "%s".', $sku, $slug));
    }

    private static function product(string $slug): Product
    {
        $products = static::getContainer()->get(ProductRepository::class);
        self::assertInstanceOf(ProductRepository::class, $products);

        $product = $products->findOneBy(['slug' => $slug]);
        self::assertInstanceOf(Product::class, $product);

        return $product;
    }

    private static function repriceShaker(int $priceCents): void
    {
        self::mutateShaker(static fn (Product $product): Product => $product->setPriceCents($priceCents));
    }

    private static function restockShaker(int $stock): void
    {
        self::mutateShaker(static fn (Product $product): Product => $product->setStock($stock));
    }

    private static function setShakerActive(bool $active): void
    {
        self::mutateShaker(static fn (Product $product): Product => $product->setActive($active));
    }

    /**
     * Puts the shaker back the way the fixtures leave it, so the suite does
     * not depend on the order it runs in.
     */
    private static function restoreShaker(): void
    {
        self::mutateShaker(static fn (Product $product): Product => $product
            ->setPriceCents(900)
            ->setStock(150)
            ->setActive(true));
    }

    /**
     * @param callable(Product): Product $change
     */
    private static function mutateShaker(callable $change): void
    {
        $wasBooted = null !== static::$kernel;

        if (!$wasBooted) {
            self::bootKernel();
        }

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $change(self::product('shaker'));
        $entityManager->flush();

        if (!$wasBooted) {
            self::ensureKernelShutdown();
        }
    }
}
