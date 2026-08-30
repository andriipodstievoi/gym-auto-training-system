<?php

declare(strict_types=1);

namespace App\Domain;

use JsonSerializable;
use Stringable;

/**
 * A short piece of customer-facing text held in every locale the site serves.
 *
 * Riga means three languages, and almost every catalogue field needs all of
 * them. Storing them as one JSON column keeps entities free of nameEn/nameLv/
 * nameRu triplets and keeps the fallback rule in exactly one place.
 */
final readonly class TranslatedString implements JsonSerializable, Stringable
{
    public const array LOCALES = ['en', 'lv', 'ru'];

    /**
     * Fallback order per locale: a Latvian reader hitting an untranslated
     * field gets English before Russian, and vice versa.
     *
     * @var array<string, list<string>>
     */
    private const array FALLBACKS = [
        'en' => ['en', 'lv', 'ru'],
        'lv' => ['lv', 'en', 'ru'],
        'ru' => ['ru', 'en', 'lv'],
    ];

    /** @var array<string, string> */
    private array $values;

    /**
     * @param array<string, string|null> $values
     */
    public function __construct(array $values = [])
    {
        $clean = [];
        foreach (self::LOCALES as $locale) {
            $value = trim((string) ($values[$locale] ?? ''));
            if ('' !== $value) {
                $clean[$locale] = $value;
            }
        }

        $this->values = $clean;
    }

    public static function of(string $en, string $lv = '', string $ru = ''): self
    {
        return new self(['en' => $en, 'lv' => $lv, 'ru' => $ru]);
    }

    /**
     * The best available translation for $locale, or an empty string when the
     * field carries no text at all.
     */
    public function get(?string $locale = null): string
    {
        $chain = self::FALLBACKS[$locale ?? 'en'] ?? self::FALLBACKS['en'];

        foreach ($chain as $candidate) {
            if (isset($this->values[$candidate])) {
                return $this->values[$candidate];
            }
        }

        return '';
    }

    public function has(string $locale): bool
    {
        return isset($this->values[$locale]);
    }

    public function isEmpty(): bool
    {
        return [] === $this->values;
    }

    /**
     * @return list<string> locales this string is still missing
     */
    public function missingLocales(): array
    {
        return array_values(array_diff(self::LOCALES, array_keys($this->values)));
    }

    public function with(string $locale, string $value): self
    {
        return new self([...$this->values, $locale => $value]);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * @return array<string, string>
     */
    public function jsonSerialize(): array
    {
        return $this->values;
    }

    public function __toString(): string
    {
        return $this->get();
    }
}
