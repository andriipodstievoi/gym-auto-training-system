<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Expects a seeded test database - see {@see BranchControllerTest}.
 */
final class TrainerControllerTest extends WebTestCase
{
    #[DataProvider('localeProvider')]
    public function testTheIndexListsEveryCoachInEveryLocale(string $locale, string $expected): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/'.$locale.'/trainers');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $expected);
        self::assertCount(4, $crawler->filter('article'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function localeProvider(): iterable
    {
        yield 'english' => ['en', 'Our trainers'];
        yield 'latvian' => ['lv', 'Mūsu treneri'];
        yield 'russian' => ['ru', 'Наши тренеры'];
    }

    public function testSpecialitiesAndLanguagesAreTranslated(): void
    {
        $client = static::createClient();

        $client->request('GET', '/en/trainers/ilze-berzina');
        self::assertSelectorTextContains('body', 'Powerlifting');
        self::assertSelectorTextContains('body', 'Latvian');

        $client->request('GET', '/ru/trainers/ilze-berzina');
        self::assertSelectorTextContains('body', 'Пауэрлифтинг');
        self::assertSelectorTextContains('body', 'Латышский');
    }

    public function testAProfileShowsTheBioAndLinksBackToTheBranch(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/en/trainers/marta-ozola');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Marta Ozola');
        self::assertSelectorTextContains('body', 'Physiotherapy degree');
        self::assertCount(1, $crawler->filter('a[href="/en/branches/agenskalns"]'));
    }

    public function testAnUnknownTrainerIsNotFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/en/trainers/nobody');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * M5 replaced the "coming soon" note with a real call to action. A visitor
     * who is not signed in is offered the sign-in, not the booking form.
     */
    public function testTheProfileOffersBookingAndSendsAnonymousVisitorsToSignIn(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/en/trainers/marta-ozola');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', 'Online booking is coming soon');
        self::assertCount(1, $crawler->filter('a[href="/en/trainers/marta-ozola/book"]'));
        self::assertSelectorTextContains('body', 'Sign in to book');

        // Nothing to message with until there is somebody to message as.
        self::assertCount(0, $crawler->filter('form[action="/en/messages/send"]'));
    }

    /**
     * A coach with no hours set says so, rather than showing an empty widget.
     */
    public function testACoachWithNoAvailabilitySaysSo(): void
    {
        $client = static::createClient();
        $client->request('GET', '/en/trainers/deniss-petrovs');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'This coach has no open slots');
    }

    /**
     * The index used to carry the same "coming soon" note. It is now false.
     */
    public function testTheIndexNoLongerPromisesBookingLater(): void
    {
        $client = static::createClient();
        $client->request('GET', '/en/trainers');

        self::assertSelectorTextNotContains('body', 'Online booking is coming soon');
    }
}
