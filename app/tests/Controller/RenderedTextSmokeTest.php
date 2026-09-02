<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * No page renders the machinery behind its own text.
 *
 * Two bugs got all the way to a rendered page during this project and neither
 * failed a single test, because both produced a valid 200 with wrong words in
 * it:
 *
 *   - the cart printed "{0}empty|{1}1 item|]1,Inf[ %count% items", because a
 *     pluralised message rendered with a bare |trans emits the raw string and
 *     Symfony only picks a plural when %count% is in the parameters;
 *   - every catalogue tile printed "from %amount% EUR29.90", because the
 *     translation carried a placeholder the template never filled.
 *
 * Both were found by a human looking at a screenshot. That is not a repeatable
 * way to catch a class of bug, so this looks instead: it walks the public
 * pages in all three languages and fails on anything that looks like an
 * unsubstituted placeholder, a raw plural, or an untranslated key.
 *
 * It deliberately asserts on text rather than on markup. What matters is what
 * a member reads.
 */
final class RenderedTextSmokeTest extends WebTestCase
{
    /**
     * Pages reachable without an account. Signed-in pages are covered by their
     * own tests; this is about breadth, not depth.
     *
     * @return iterable<string, array{string}>
     */
    public static function pathProvider(): iterable
    {
        $paths = [
            '',
            '/branches',
            '/branches/centrs',
            '/memberships',
            '/trainers',
            '/trainers/ilze-berzina',
            '/trainers/ilze-berzina/book',
            '/shop',
            '/shop/c/protein',
            '/shop/p/whey-vanilla',
            '/shop/p/lifting-belt',
            '/cart',
            '/login',
            '/register',
        ];

        foreach (['en', 'lv', 'ru'] as $locale) {
            foreach ($paths as $path) {
                yield $locale.$path => ['/'.$locale.$path];
            }
        }
    }

    #[DataProvider('pathProvider')]
    public function testAPageShowsNoUnrenderedMachinery(string $path): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', $path);

        self::assertResponseIsSuccessful(sprintf('%s should render.', $path));

        $text = $crawler->filter('main')->count() > 0
            ? $crawler->filter('main')->text('', true)
            : $crawler->filter('body')->text('', true);

        // "%amount%", "%count%", "%plan%" - a parameter the template never passed.
        self::assertDoesNotMatchRegularExpression(
            '/%[a-z_]+%/',
            $text,
            sprintf('%s renders an unsubstituted placeholder.', $path),
        );

        // "]1,Inf[" or "{0}...|{1}..." - a pluralised string rendered whole.
        self::assertStringNotContainsString(
            ']1,Inf[',
            $text,
            sprintf('%s renders a raw pluralisation string; pass %%count%% to |trans.', $path),
        );

        // "shop.cart.total" - a key with no translation behind it. Matched
        // conservatively: two or more dotted lowercase segments, which no
        // sentence on this site produces.
        self::assertDoesNotMatchRegularExpression(
            '/\b(?:shop|nav|account|plan|assessment|booking|coach|message|trainer|membership|branch|email|a11y)\.[a-z_]+(?:\.[a-z_]+)*\b/',
            $text,
            sprintf('%s renders a raw translation key.', $path),
        );
    }

    /**
     * The same check, on pages that only exist once there is something in the
     * cart.
     *
     * An empty cart renders neither the item table nor the count, which is
     * where the pluralisation bug actually lived - so walking /cart in the
     * provider above proves nothing about it. Verified by reintroducing the
     * bug: this fails, that does not.
     */
    #[DataProvider('localeProvider')]
    public function testACartWithSomethingInItShowsNoUnrenderedMachinery(string $locale): void
    {
        $client = static::createClient();

        // Two of them, so the plural branch is the one that renders.
        $client->request('GET', '/'.$locale.'/shop/p/shaker');
        $client->request('POST', '/'.$locale.'/cart/add', [
            '_token' => 'csrf-token',
            'slug' => 'shaker',
            'quantity' => '2',
        ]);

        $crawler = $client->request('GET', '/'.$locale.'/cart');
        self::assertResponseIsSuccessful();

        $text = $crawler->filter('main')->text('', true);

        self::assertStringContainsString(
            '2',
            $text,
            'The cart should be showing two items, or this asserts nothing.',
        );

        self::assertDoesNotMatchRegularExpression(
            '/%[a-z_]+%/',
            $text,
            sprintf('The %s cart renders an unsubstituted placeholder.', $locale),
        );

        self::assertStringNotContainsString(
            ']1,Inf[',
            $text,
            sprintf('The %s cart renders a raw pluralisation string; pass %%count%% to |trans.', $locale),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function localeProvider(): iterable
    {
        yield 'english' => ['en'];
        yield 'latvian' => ['lv'];
        yield 'russian' => ['ru'];
    }
}
