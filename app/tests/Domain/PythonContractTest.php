<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\Enum\Equipment;
use App\Domain\Enum\Experience;
use App\Domain\Enum\Goal;
use App\Domain\Enum\Limitation;
use App\Domain\Enum\PlanStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The two runtimes agree about the questionnaire.
 *
 * ADR 0001 accepts one real cost for splitting the generator out: a contract
 * that has to be kept in sync on both sides. Four enums cross that wire, and
 * every one of them has a docblock asking the next person to keep it in step
 * with Python. A docblock cannot fail a build, so this does.
 *
 * It reads the Pydantic schemas as text rather than importing them, because
 * the point is to catch a drift committed by somebody who never ran the Python
 * service - which is most people editing the PHP side.
 *
 * A mismatch here is not a test to relax. It means a member would answer a
 * question the engine does not understand, and the engine would either reject
 * the assessment or quietly plan for something they did not ask for.
 */
final class PythonContractTest extends TestCase
{
    private const string SCHEMAS = __DIR__.'/../../../ai-service/app/schemas.py';

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function enumProvider(): iterable
    {
        yield 'Goal' => ['Goal', array_map(static fn (Goal $c): string => $c->value, Goal::cases())];
        yield 'Experience' => ['Experience', array_map(static fn (Experience $c): string => $c->value, Experience::cases())];
        yield 'Equipment' => ['Equipment', array_map(static fn (Equipment $c): string => $c->value, Equipment::cases())];
        yield 'Limitation' => ['Limitation', array_map(static fn (Limitation $c): string => $c->value, Limitation::cases())];
        yield 'PlanStatus' => ['PlanStatus', array_map(static fn (PlanStatus $c): string => $c->value, PlanStatus::cases())];
    }

    /**
     * @param list<string> $phpValues
     */
    #[DataProvider('enumProvider')]
    public function testThePhpEnumMirrorsThePythonOne(string $pythonEnum, array $phpValues): void
    {
        $pythonValues = self::pythonEnumValues($pythonEnum);

        self::assertSame(
            $pythonValues,
            $phpValues,
            sprintf(
                'App\Domain\Enum\%s and %s in ai-service/app/schemas.py have drifted apart. '
                .'Change both, in the same commit, or the questionnaire and the engine stop agreeing.',
                $pythonEnum,
                $pythonEnum,
            ),
        );
    }

    public function testTheSchemasFileIsWhereThisTestThinksItIs(): void
    {
        // Guards the rest of the class: a moved file must fail loudly rather
        // than silently turning every comparison above into a no-op.
        self::assertFileExists(self::SCHEMAS);
    }

    /**
     * The backing values of one StrEnum in schemas.py, in declaration order.
     *
     * @return list<string>
     */
    private static function pythonEnumValues(string $name): array
    {
        $source = file_get_contents(self::SCHEMAS);

        if (false === $source) {
            throw new RuntimeException(sprintf('Could not read %s.', self::SCHEMAS));
        }

        // The class body runs to the first line that is neither indented nor
        // blank, which is where the next top-level definition starts.
        $pattern = sprintf('/^class %s\(StrEnum\):\R((?:[ \t]+.*\R|\R)*)/m', preg_quote($name, '/'));

        if (1 !== preg_match($pattern, $source, $matches)) {
            throw new RuntimeException(sprintf('No StrEnum called "%s" in %s.', $name, self::SCHEMAS));
        }

        if (1 > preg_match_all('/^[ \t]+[A-Z0-9_]+\s*=\s*"([^"]+)"/m', $matches[1], $members)) {
            throw new RuntimeException(sprintf('StrEnum "%s" has no members.', $name));
        }

        return $members[1];
    }
}
