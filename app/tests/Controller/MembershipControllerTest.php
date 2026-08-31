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
     * Checkout is M3, so the page must not pretend to sell anything yet.
     */
    public function testNothingOnThePageTriesToTakeMoney(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/en/memberships');

        self::assertCount(0, $crawler->filter('form'));
        self::assertSelectorTextContains('body', 'Online checkout is coming soon');
    }
}
