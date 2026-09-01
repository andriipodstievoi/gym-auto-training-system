<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * The three catalogues say the same things.
 *
 * A missing key does not fail anything at runtime: Symfony falls back, and if
 * the fallback misses too the raw dotted key renders on the page. That is the
 * quietest kind of bug this site has - it only shows up to somebody browsing in
 * Latvian or Russian, which is nobody on the team.
 *
 * So the parity is asserted rather than trusted. English is the reference
 * because it is the language the keys are invented in.
 */
final class TranslationParityTest extends TestCase
{
    private const string DIR = __DIR__.'/../../translations';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function catalogueProvider(): iterable
    {
        foreach (['messages', 'validators'] as $domain) {
            foreach (['lv', 'ru'] as $locale) {
                yield $domain.' in '.$locale => [$domain, $locale];
            }
        }
    }

    #[DataProvider('catalogueProvider')]
    public function testEveryEnglishKeyExistsInTheOtherCatalogues(string $domain, string $locale): void
    {
        $english = self::keys($domain, 'en');
        $other = self::keys($domain, $locale);

        $missing = array_diff($english, $other);
        $extra = array_diff($other, $english);

        self::assertSame(
            [],
            array_values($missing),
            sprintf('%s.%s.yaml is missing keys that exist in English. Untranslated keys render as the raw dotted string.', $domain, $locale),
        );

        self::assertSame(
            [],
            array_values($extra),
            sprintf('%s.%s.yaml has keys English does not. Either English is missing them or they are dead.', $domain, $locale),
        );
    }

    /**
     * Every leaf key in one catalogue, flattened to dotted form.
     *
     * @return list<string>
     */
    private static function keys(string $domain, string $locale): array
    {
        $path = sprintf('%s/%s.%s.yaml', self::DIR, $domain, $locale);
        $parsed = Yaml::parseFile($path);

        if (!is_array($parsed)) {
            throw new RuntimeException(sprintf('%s did not parse to a map.', $path));
        }

        $keys = [];
        self::flatten($parsed, '', $keys);
        sort($keys);

        return $keys;
    }

    /**
     * @param array<array-key, mixed> $node
     * @param list<string>            $keys
     */
    private static function flatten(array $node, string $prefix, array &$keys): void
    {
        foreach ($node as $key => $value) {
            $path = '' === $prefix ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                self::flatten($value, $path, $keys);

                continue;
            }

            $keys[] = $path;
        }
    }
}
