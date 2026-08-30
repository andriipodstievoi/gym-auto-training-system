<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\TranslatedString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TranslatedStringTest extends TestCase
{
    public function testReturnsTheRequestedLocaleWhenPresent(): void
    {
        $string = TranslatedString::of('Squat', 'Pietupiens', 'Присед');

        self::assertSame('Squat', $string->get('en'));
        self::assertSame('Pietupiens', $string->get('lv'));
        self::assertSame('Присед', $string->get('ru'));
    }

    #[DataProvider('fallbackProvider')]
    public function testFallsBackInTheDocumentedOrder(string $requested, string $expected): void
    {
        // Only Latvian and Russian are filled in.
        $string = new TranslatedString(['lv' => 'Pietupiens', 'ru' => 'Присед']);

        self::assertSame($expected, $string->get($requested));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function fallbackProvider(): iterable
    {
        yield 'english falls back to latvian first' => ['en', 'Pietupiens'];
        yield 'latvian is present' => ['lv', 'Pietupiens'];
        yield 'russian is present' => ['ru', 'Присед'];
    }

    public function testRussianFallsBackToEnglishBeforeLatvian(): void
    {
        $string = new TranslatedString(['en' => 'Squat', 'lv' => 'Pietupiens']);

        self::assertSame('Squat', $string->get('ru'));
    }

    public function testUnknownLocaleUsesTheEnglishChain(): void
    {
        $string = TranslatedString::of('Squat', 'Pietupiens', 'Присед');

        self::assertSame('Squat', $string->get('de'));
    }

    public function testEmptyAndWhitespaceValuesAreDiscarded(): void
    {
        $string = new TranslatedString(['en' => '  ', 'lv' => '', 'ru' => null]);

        self::assertTrue($string->isEmpty());
        self::assertSame('', $string->get('en'));
        self::assertSame(['en', 'lv', 'ru'], $string->missingLocales());
    }

    public function testValuesAreTrimmed(): void
    {
        $string = new TranslatedString(['en' => '  Squat  ']);

        self::assertSame('Squat', $string->get('en'));
    }

    public function testUnknownLocalesAreNotStored(): void
    {
        $string = new TranslatedString(['en' => 'Squat', 'de' => 'Kniebeuge']);

        self::assertSame(['en' => 'Squat'], $string->toArray());
    }

    public function testWithReturnsANewInstanceAndLeavesTheOriginalAlone(): void
    {
        $original = TranslatedString::of('Squat');
        $updated = $original->with('lv', 'Pietupiens');

        self::assertNotSame($original, $updated);
        self::assertSame(['en' => 'Squat'], $original->toArray());
        self::assertFalse($original->has('lv'));

        self::assertTrue($updated->has('lv'));
        self::assertSame('Pietupiens', $updated->get('lv'));

        // The original still answers for Latvian - by falling back to English.
        self::assertSame('Squat', $original->get('lv'));
    }

    public function testMissingLocalesReportsOnlyWhatIsAbsent(): void
    {
        $string = TranslatedString::of('Squat', 'Pietupiens');

        self::assertSame(['ru'], $string->missingLocales());
    }

    public function testCastsToTheDefaultLocale(): void
    {
        self::assertSame('Squat', (string) TranslatedString::of('Squat', 'Pietupiens'));
    }

    public function testSerialisesToTheStoredMap(): void
    {
        $string = TranslatedString::of('Squat', 'Pietupiens', 'Присед');

        self::assertSame(
            '{"en":"Squat","lv":"Pietupiens","ru":"Присед"}',
            json_encode($string, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
        );
    }
}
