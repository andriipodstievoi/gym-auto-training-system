<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use App\Domain\TranslatedString;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\Type;
use JsonException;

/**
 * Maps {@see TranslatedString} onto a JSON column.
 *
 * Registered as "translated_string" in config/packages/doctrine.yaml. DBAL 4
 * types have no getName(), so the name lives in configuration only.
 */
final class TranslatedStringType extends Type
{
    public const string NAME = 'translated_string';

    /**
     * @param array<string, mixed> $column
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getJsonTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!$value instanceof TranslatedString) {
            throw InvalidType::new($value, self::NAME, ['null', TranslatedString::class]);
        }

        return json_encode($value->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?TranslatedString
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof TranslatedString) {
            return $value;
        }

        try {
            /** @var array<string, string> $decoded */
            $decoded = json_decode((string) $value, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw ValueNotConvertible::new($value, self::NAME, $e->getMessage(), $e);
        }

        return new TranslatedString($decoded);
    }
}
