<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Doctrine\Type\TranslatedStringType;
use App\Domain\TranslatedString;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use JsonException;
use PHPUnit\Framework\TestCase;

/**
 * The JSON column behind every translated field.
 *
 * This is the seam where a value object meets a text column, so both
 * directions matter and so does what happens when either side is handed
 * something it did not write. DBAL 4 dropped Type::getName() and the old
 * ConversionException factories, which is why the failures below are asserted
 * as the specific Exception\* classes rather than a bare ConversionException.
 */
final class TranslatedStringTypeTest extends TestCase
{
    public function testAStringGoesToTheDatabaseAsItsLocaleMap(): void
    {
        $encoded = $this->type()->convertToDatabaseValue(
            TranslatedString::of('Squat', 'Pietupiens', 'Присед'),
            $this->platform(),
        );

        self::assertSame('{"en":"Squat","lv":"Pietupiens","ru":"Присед"}', $encoded);
    }

    /**
     * Cyrillic is half the catalogue, and \uXXXX escapes would make the column
     * unreadable to anybody looking at it in a client.
     */
    public function testNonLatinTextIsStoredReadableRatherThanEscaped(): void
    {
        $encoded = $this->type()->convertToDatabaseValue(TranslatedString::of('', '', 'Присед'), $this->platform());

        self::assertIsString($encoded);
        self::assertStringNotContainsString('\u', $encoded);
    }

    public function testLocalesWithNoTextAreNotWrittenAtAll(): void
    {
        $encoded = $this->type()->convertToDatabaseValue(TranslatedString::of('Squat'), $this->platform());

        self::assertSame('{"en":"Squat"}', $encoded);
    }

    public function testNullSurvivesInBothDirections(): void
    {
        $type = $this->type();

        self::assertNull($type->convertToDatabaseValue(null, $this->platform()));
        self::assertNull($type->convertToPHPValue(null, $this->platform()));
    }

    public function testAnythingOtherThanATranslatedStringIsRefused(): void
    {
        $this->expectException(InvalidType::class);

        $this->type()->convertToDatabaseValue('just a string', $this->platform());
    }

    public function testAnArrayIsRefusedRatherThanQuietlyEncoded(): void
    {
        $this->expectException(InvalidType::class);

        $this->type()->convertToDatabaseValue(['en' => 'Squat'], $this->platform());
    }

    public function testAStoredMapComesBackAsAValueObject(): void
    {
        $value = $this->type()->convertToPHPValue('{"en":"Squat","lv":"Pietupiens"}', $this->platform());

        self::assertInstanceOf(TranslatedString::class, $value);
        self::assertSame('Squat', $value->get('en'));
        self::assertSame('Pietupiens', $value->get('lv'));

        // The fallback rule travels with the object, not with the column.
        self::assertSame('Squat', $value->get('ru'));
    }

    /**
     * Doctrine hands hydrated values back through the type on flush, so a
     * value object arriving here must pass through untouched rather than be
     * cast to a string first.
     */
    public function testAValueObjectPassesStraightThrough(): void
    {
        $original = TranslatedString::of('Squat');
        $value = $this->type()->convertToPHPValue($original, $this->platform());

        self::assertSame($original, $value);
    }

    public function testMalformedJsonIsReportedAsAFailedConversion(): void
    {
        $this->expectException(ValueNotConvertible::class);

        $this->type()->convertToPHPValue('{"en": "Squat"', $this->platform());
    }

    public function testTheOriginalJsonErrorIsKeptAsTheCause(): void
    {
        try {
            $this->type()->convertToPHPValue('not json at all', $this->platform());
        } catch (ValueNotConvertible $e) {
            self::assertInstanceOf(JsonException::class, $e->getPrevious());

            return;
        }

        self::fail('Malformed JSON should not have converted.');
    }

    public function testAStringRoundTripsThroughTheColumnUnchanged(): void
    {
        $type = $this->type();
        $original = TranslatedString::of('Training tee', 'Treniņu krekls', 'Футболка');

        $value = $type->convertToPHPValue($type->convertToDatabaseValue($original, $this->platform()), $this->platform());

        self::assertInstanceOf(TranslatedString::class, $value);
        self::assertSame($original->toArray(), $value->toArray());
    }

    /**
     * A field nobody has filled in yet is still a TranslatedString, and it has
     * to survive a trip through the column as one - an entity that got null
     * back would break every ->get() the templates make.
     */
    public function testAnEmptyStringRoundTripsAsAnEmptyStringRatherThanNull(): void
    {
        $type = $this->type();

        $value = $type->convertToPHPValue($type->convertToDatabaseValue(new TranslatedString(), $this->platform()), $this->platform());

        self::assertInstanceOf(TranslatedString::class, $value);
        self::assertTrue($value->isEmpty());
        self::assertSame('', $value->get('lv'));
    }

    public function testTheColumnIsWhateverJsonIsOnThisPlatform(): void
    {
        $platform = self::createStub(AbstractPlatform::class);
        $platform->method('getJsonTypeDeclarationSQL')->willReturn('JSON');

        self::assertSame('JSON', $this->type()->getSQLDeclaration(['name' => 'description'], $platform));
    }

    private function type(): TranslatedStringType
    {
        return new TranslatedStringType();
    }

    /**
     * Neither conversion asks the platform anything, so a bare stub is all
     * either direction needs.
     */
    private function platform(): AbstractPlatform
    {
        return self::createStub(AbstractPlatform::class);
    }
}
