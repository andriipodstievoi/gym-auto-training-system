<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomeControllerTest extends WebTestCase
{
    public function testRootRedirectsToTheDefaultLocale(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseRedirects('/en');
    }

    #[DataProvider('localeProvider')]
    public function testLandingPageRendersInEveryLocale(string $locale, string $expected): void
    {
        $client = static::createClient();
        $client->request('GET', '/'.$locale);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $expected);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function localeProvider(): iterable
    {
        yield 'english' => ['en', 'Train with a plan built around you'];
        yield 'latvian' => ['lv', 'Trenējies pēc plāna'];
        yield 'russian' => ['ru', 'Тренируйся по плану'];
    }

    public function testUnsupportedLocaleIsNotRouted(): void
    {
        $client = static::createClient();
        $client->request('GET', '/de');

        self::assertResponseStatusCodeSame(404);
    }
}
