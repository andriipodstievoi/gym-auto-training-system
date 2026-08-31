<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\OpeningSchedule;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpeningScheduleTest extends TestCase
{
    /**
     * The shape the fixtures store: Monday to Thursday alike, then a shorter
     * Friday and a shorter weekend.
     *
     * @var array<int, array{open: string, close: string}>
     */
    private const array WEEK = [
        1 => ['open' => '06:00', 'close' => '23:00'],
        2 => ['open' => '06:00', 'close' => '23:00'],
        3 => ['open' => '06:00', 'close' => '23:00'],
        4 => ['open' => '06:00', 'close' => '23:00'],
        5 => ['open' => '06:00', 'close' => '22:00'],
        6 => ['open' => '08:00', 'close' => '21:00'],
        7 => ['open' => '09:00', 'close' => '20:00'],
    ];

    public function testAnEmptyScheduleIsEmpty(): void
    {
        $schedule = OpeningSchedule::fromArray([]);

        self::assertTrue($schedule->isEmpty());
        self::assertSame([], $schedule->grouped());
    }

    public function testADayCarriesItsOwnHours(): void
    {
        $friday = OpeningSchedule::fromArray(self::WEEK)->forDay(5);

        self::assertNotNull($friday);
        self::assertSame('06:00', $friday->open);
        self::assertSame('22:00', $friday->close);
    }

    public function testAClosedDayHasNoHours(): void
    {
        $schedule = OpeningSchedule::fromArray([1 => ['open' => '06:00', 'close' => '23:00']]);

        self::assertNull($schedule->forDay(2));
    }

    public function testGarbageDaysAreDiscarded(): void
    {
        // Anything outside ISO 1-7, or missing a bound, is not a day we can show.
        $schedule = OpeningSchedule::fromArray([
            0 => ['open' => '06:00', 'close' => '23:00'],
            9 => ['open' => '06:00', 'close' => '23:00'],
            3 => ['open' => '06:00', 'close' => '23:00'],
        ]);

        self::assertNull($schedule->forDay(0));
        self::assertNull($schedule->forDay(9));
        self::assertNotNull($schedule->forDay(3));
    }

    public function testConsecutiveIdenticalDaysCollapseIntoOneRun(): void
    {
        $runs = OpeningSchedule::fromArray(self::WEEK)->grouped();

        self::assertCount(4, $runs);

        self::assertSame(1, $runs[0]->firstDay);
        self::assertSame(4, $runs[0]->lastDay);
        self::assertSame('06:00', $runs[0]->period->open);
        self::assertSame('23:00', $runs[0]->period->close);

        self::assertSame(5, $runs[1]->firstDay);
        self::assertSame(5, $runs[1]->lastDay);

        self::assertSame(7, $runs[3]->firstDay);
        self::assertSame(7, $runs[3]->lastDay);
    }

    public function testAClosedDayBreaksARun(): void
    {
        $runs = OpeningSchedule::fromArray([
            1 => ['open' => '06:00', 'close' => '23:00'],
            3 => ['open' => '06:00', 'close' => '23:00'],
        ])->grouped();

        self::assertCount(2, $runs);
        self::assertSame(1, $runs[0]->lastDay);
        self::assertSame(3, $runs[1]->firstDay);
    }

    #[DataProvider('momentProvider')]
    public function testOpenNowFollowsTheClock(string $moment, bool $expected): void
    {
        // 2026-08-31 is a Monday, so 2026-09-05 is the Saturday of that week.
        self::assertSame(
            $expected,
            OpeningSchedule::fromArray(self::WEEK)->isOpenAt(new DateTimeImmutable($moment)),
        );
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function momentProvider(): iterable
    {
        yield 'monday mid-morning' => ['2026-08-31 10:00', true];
        yield 'monday before opening' => ['2026-08-31 05:59', false];
        yield 'monday on the dot' => ['2026-08-31 06:00', true];
        yield 'monday at closing' => ['2026-08-31 23:00', false];
        yield 'saturday early, still shut' => ['2026-09-05 07:30', false];
        yield 'saturday once open' => ['2026-09-05 08:30', true];
    }

    public function testAClosedDayIsNeverOpen(): void
    {
        $schedule = OpeningSchedule::fromArray([1 => ['open' => '06:00', 'close' => '23:00']]);

        self::assertFalse($schedule->isOpenAt(new DateTimeImmutable('2026-09-01 10:00')));
    }

    public function testAPeriodRunningPastMidnightStaysOpen(): void
    {
        $schedule = OpeningSchedule::fromArray([1 => ['open' => '22:00', 'close' => '04:00']]);

        self::assertTrue($schedule->isOpenAt(new DateTimeImmutable('2026-08-31 23:30')));
        self::assertTrue($schedule->isOpenAt(new DateTimeImmutable('2026-08-31 01:00')));
        self::assertFalse($schedule->isOpenAt(new DateTimeImmutable('2026-08-31 12:00')));
    }
}
