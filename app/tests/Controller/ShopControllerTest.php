<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Product;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The public catalogue. Expects a seeded test database.
 *
 * The assertions are on catalogue data rather than on copy: the translation
 * files are written separately, and a test that pins wording breaks every time
 * somebody rephrases a heading.
 */
final class ShopControllerTest extends WebTestCase
{
    protected function tearDown(): void
    {
        self::reactivate('shaker');
        parent::tearDown();
    }

    #[DataProvider('localeProvider')]
    public function testTheShopIndexRendersInEveryLocale(string $locale, string $expected): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/'.$locale.'/shop');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($expected, $crawler->filter('body')->text());
    }

    #[DataProvider('localeProvider')]
    public function testACategoryRendersInEveryLocale(string $locale, string $expected): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/'.$locale.'/shop/c/protein');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($expected, $crawler->filter('body')->text());
    }

    #[DataProvider('productLocaleProvider')]
    public function testAProductRendersInEveryLocale(string $locale, string $expected): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/'.$locale.'/shop/p/shaker');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($expected, $crawler->filter('body')->text());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function localeProvider(): iterable
    {
        yield 'english' => ['en', 'Whey protein, vanilla'];
        yield 'latvian' => ['lv', 'Sūkalu proteīns'];
        yield 'russian' => ['ru', 'Сывороточный протеин'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function productLocaleProvider(): iterable
    {
        yield 'english' => ['en', 'Shaker, 700 ml'];
        yield 'latvian' => ['lv', 'Šeikeris, 700 ml'];
        yield 'russian' => ['ru', 'Шейкер, 700 мл'];
    }

    public function testTheIndexGroupsProductsUnderTheirCategories(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/en/shop');

        self::assertResponseIsSuccessful();

        // Four seeded categories, nine seeded products.
        self::assertCount(4, $crawler->filter('a[href^="/en/shop/c/"]'));
        self::assertCount(9, $crawler->filter('article'));
    }

    public function testAProductWithVariantsOffersARealLabelledPicker(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/en/shop/p/training-hoodie');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('select#variant'));
        self::assertCount(1, $crawler->filter('label[for="variant"]'));
        self::assertCount(4, $crawler->filter('select#variant option'));
    }

    public function testAProductWithoutVariantsHasNoPicker(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/en/shop/p/shaker');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('select#variant'));
        self::assertCount(1, $crawler->filter('label[for="quantity"]'));
    }

    public function testAnUnknownProductIsNotFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/en/shop/p/no-such-product');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnInactiveProductIsNotFound(): void
    {
        self::setActive('shaker', false);

        $client = static::createClient();
        $client->request('GET', '/en/shop/p/shaker');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnUnknownCategoryIsNotFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/en/shop/c/no-such-category');

        self::assertResponseStatusCodeSame(404);
    }

    private static function setActive(string $slug, bool $active): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $products = static::getContainer()->get(ProductRepository::class);
        self::assertInstanceOf(ProductRepository::class, $products);

        $product = $products->findOneBy(['slug' => $slug]);
        self::assertInstanceOf(Product::class, $product);

        $product->setActive($active);
        $entityManager->flush();

        self::ensureKernelShutdown();
    }

    private static function reactivate(string $slug): void
    {
        self::setActive($slug, true);
    }
}
