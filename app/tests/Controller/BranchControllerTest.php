<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Expects the test database to be migrated and seeded:
 *   bin/console doctrine:migrations:migrate --env=test
 *   bin/console doctrine:fixtures:load --env=test
 */
final class BranchControllerTest extends WebTestCase
{
    #[DataProvider('localeProvider')]
    public function testTheIndexRendersInEveryLocale(string $locale, string $expected): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/'.$locale.'/branches');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $expected);
        self::assertCount(3, $crawler->filter('article'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function localeProvider(): iterable
    {
        yield 'english' => ['en', 'Find your branch'];
        yield 'latvian' => ['lv', 'Atrodi savu filiāli'];
        yield 'russian' => ['ru', 'Найди свой зал'];
    }

    public function testTheMapIsHandedEveryBranchWithItsCoordinates(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/en/branches');

        $payload = $crawler->filter('[data-controller="branch-map"]')->attr('data-branches');
        self::assertIsString($payload);

        /** @var list<array<string, mixed>> $markers */
        $markers = json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);

        self::assertCount(3, $markers);

        foreach ($markers as $marker) {
            // Riga, not the Atlantic: a swapped lat/lng would sail through a
            // looser assertion and put every pin in the wrong hemisphere.
            self::assertGreaterThan(56.0, $marker['lat']);
            self::assertLessThan(58.0, $marker['lat']);
            self::assertGreaterThan(23.0, $marker['lng']);
            self::assertLessThan(25.0, $marker['lng']);
            self::assertStringStartsWith('/en/branches/', (string) $marker['url']);
            self::assertNotSame('', $marker['name']);
        }

        self::assertSame(
            ['agenskalns', 'centrs', 'purvciems'],
            array_map(static fn (array $m): string => (string) $m['slug'], $markers),
        );
    }

    public function testTheMapLinksFollowTheLocale(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/lv/branches');

        $payload = (string) $crawler->filter('[data-controller="branch-map"]')->attr('data-branches');
        /** @var list<array<string, mixed>> $markers */
        $markers = json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);

        self::assertStringStartsWith('/lv/branches/', (string) $markers[0]['url']);
    }

    public function testABranchPageShowsItsHoursAndContactDetails(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/en/branches/centrs');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'SPĒKS Centrs');
        self::assertSelectorTextContains('body', 'Brīvības iela 55');
        self::assertStringContainsString('06:00', $crawler->filter('dl')->first()->text());
        self::assertCount(1, $crawler->filter('a[href^="mailto:centrs@speks.lv"]'));
    }

    /**
     * The point of the milestone: every zone in the database becomes a shape in
     * the drawing, addressed by its own svgId - amenity rooms included, so all
     * of them are clickable.
     */
    public function testEveryRoomIsAShapeAndEveryShapeIsClickable(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/en/branches/centrs');

        $rooms = [
            'free-weights', 'machines', 'cardio', 'functional',
            'changing-men', 'changing-women', 'reception',
            'lounge', 'spa',
        ];

        foreach ($rooms as $svgId) {
            self::assertCount(1, $crawler->filter('rect#zone-'.$svgId), $svgId.' has no shape');
        }

        self::assertCount(count($rooms), $crawler->filter('svg g[role="button"]'));
    }

    public function testChangingRoomsAreSplitBySex(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/en/branches/centrs');

        self::assertCount(1, $crawler->filter('rect#zone-changing-men'));
        self::assertCount(1, $crawler->filter('rect#zone-changing-women'));
        self::assertCount(0, $crawler->filter('rect#zone-changing-rooms'));
    }

    /**
     * The lounge and spa belong upstairs, which is the whole reason the plan
     * is drawn one storey at a time.
     */
    public function testTheLoungeAndSpaAreOnTheirOwnStorey(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/en/branches/centrs');

        self::assertCount(2, $crawler->filter('[role="tablist"] button'));
        self::assertSelectorTextContains('[role="tablist"]', 'Ground floor');
        self::assertSelectorTextContains('[role="tablist"]', 'Upper floor');

        // The upper storey draws exactly the two rooms that live on it.
        $upstairs = $crawler->filter('[x-show="floor === 1"]')->first();
        self::assertStringContainsString('Lounge', $upstairs->text());
        self::assertStringContainsString('Spa', $upstairs->text());
        self::assertStringNotContainsString('Free weights', $upstairs->text());
    }

    public function testEveryBranchHasALoungeAndASpa(): void
    {
        $client = static::createClient();

        foreach (['centrs', 'purvciems', 'agenskalns'] as $slug) {
            $crawler = $client->request('GET', '/en/branches/'.$slug);

            self::assertCount(1, $crawler->filter('rect#zone-lounge'), $slug.' has no lounge');
            self::assertCount(1, $crawler->filter('rect#zone-spa'), $slug.' has no spa');
        }
    }

    public function testAZoneRevealsItsOwnEquipment(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/en/branches/centrs');

        $panel = $crawler->filter('[x-show="open === \'cardio\'"]');

        self::assertCount(1, $panel);
        self::assertStringContainsString('Treadmill', $panel->text());
        self::assertStringContainsString('Rowing machine', $panel->text());
        self::assertStringNotContainsString('Power rack', $panel->text());
    }

    /**
     * The detailed view draws one footprint per machine, not one per line, so
     * ten treadmills and four rowers are fourteen shapes.
     */
    public function testTheDetailedPlanDrawsEveryIndividualMachine(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/en/branches/centrs');

        $cardio = $crawler->filter('[x-show="open === \'cardio\'"]')->first();

        // One outer building rect, then one per machine.
        self::assertCount(15, $cardio->filter('svg rect'));
    }

    public function testAmenityRoomsGetTheirOwnDetailedPlan(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/en/branches/centrs');

        $spa = $crawler->filter('[x-show="open === \'spa\'"]')->first();

        self::assertStringContainsString('Finnish sauna', $spa->text());
        self::assertStringContainsString('Plunge pool', $spa->text());

        // Sauna, steam room, plunge pool and six loungers.
        self::assertCount(9, $spa->filter('svg g[data-group] rect'));
    }

    public function testEquipmentIsTranslated(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/lv/branches/centrs');

        self::assertStringContainsString(
            'Skrejceliņš',
            $crawler->filter('[x-show="open === \'cardio\'"]')->text(),
        );

        self::assertStringContainsString(
            'Vīriešu ģērbtuve',
            $crawler->filter('[x-show="open === \'changing-men\'"]')->text(),
        );
    }

    public function testABranchOnlyDrawsTheZonesItActuallyHas(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/en/branches/purvciems');

        // Three training zones plus the shared amenity rooms.
        self::assertCount(8, $crawler->filter('svg g[role="button"]'));
        self::assertCount(0, $crawler->filter('rect#zone-functional'));
        self::assertCount(1, $crawler->filter('rect#zone-machines'));
    }

    public function testABranchPageListsItsOwnCoaches(): void
    {
        $client = static::createClient();
        $client->request('GET', '/en/branches/agenskalns');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Marta Ozola');
        self::assertSelectorTextNotContains('body', 'Deniss Petrovs');
    }

    public function testAnUnknownBranchIsNotFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/en/branches/jurmala');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnUnsupportedLocaleIsNotRouted(): void
    {
        $client = static::createClient();
        $client->request('GET', '/de/branches');

        self::assertResponseStatusCodeSame(404);
    }
}
