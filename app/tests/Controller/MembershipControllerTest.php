<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Expects a seeded test database - see {@see BranchControllerTest}.
 */
final class MembershipControllerTest extends WebTestCase
{
    /**
     * These tests describe the page as it renders with no Stripe keys, which
     * is how a fresh clone and CI both run. Set them explicitly so the class
     * does not inherit whatever {@see CheckoutControllerTest} left behind.
     */
    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();

        foreach (['STRIPE_SECRET_KEY', 'STRIPE_PUBLIC_KEY', 'STRIPE_WEBHOOK_SECRET'] as $name) {
            $_ENV[$name] = '';
            $_SERVER[$name] = '';
        }
    }

    #[DataProvider('localeProvider')]
    public function testEveryActivePlanIsListedInEveryLocale(string $locale, string $expected): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/'.$locale.'/memberships');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $expected);
        self::assertCount(3, $crawler->filter('article'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function localeProvider(): iterable
    {
        yield 'english' => ['en', 'Pick the access you need'];
        yield 'latvian' => ['lv', 'Izvēlies sev vajadzīgo piekļuvi'];
        yield 'russian' => ['ru', 'Выбери нужный доступ'];
    }

    public function testPlanNamesComeFromTheTranslatedColumn(): void
    {
        $client = static::createClient();

        $client->request('GET', '/en/memberships');
        self::assertSelectorTextContains('body', 'All branches');

        $client->request('GET', '/ru/memberships');
        self::assertSelectorTextContains('body', 'Все филиалы');
    }

    public function testPricesAreShownInEuro(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/en/memberships');

        $text = $crawler->filter('article')->first()->text();

        self::assertStringContainsString('34.90', $text);
        self::assertStringContainsString('€', $text);
    }

    public function testFeatureBulletsAreRendered(): void
    {
        $client = static::createClient();
        $client->request('GET', '/en/memberships');

        self::assertSelectorTextContains('body', 'One branch, unlimited visits');
    }

    /**
     * With no Stripe keys the page must still render in full, and offer
     * nothing to click. The rest of the checkout behaviour, configured and
     * not, lives in {@see CheckoutControllerTest}.
     */
    public function testWithNoStripeKeysThePageSellsNothing(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/en/memberships');

        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('article'));
        self::assertCount(0, $crawler->filter('form'));
        self::assertSelectorTextContains('body', 'Checkout unavailable');
    }
}
