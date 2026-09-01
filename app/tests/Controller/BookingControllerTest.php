<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\Enum\BookingStatus;
use App\Entity\Booking;
use App\Entity\User;
use App\Repository\BookingRepository;
use App\Repository\TrainerRepository;
use App\Repository\UserRepository;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Booking a session, from the member's side.
 *
 * Expects a seeded test database - see {@see BranchControllerTest}. The seeded
 * coaches are Ilze (Mon/Wed/Fri hours, no login), Artjoms (four evenings and a
 * Saturday, linked to coach@speks.lv) and Deniss (no hours at all).
 */
final class BookingControllerTest extends WebTestCase
{
    #[DataProvider('localeProvider')]
    public function testTheSlotPickerRendersForACoachWithHours(string $locale): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/'.$locale.'/trainers/ilze-berzina/book');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Ilze Bērziņa');
        self::assertGreaterThan(0, $crawler->filter('li, label')->count());
    }

    /**
     * The database, not PHP, is what actually stops a double booking - and it
     * has to stop only the bookings that are really still holding the hour.
     *
     * Keying the unique index on starts_at would have made a declined hour
     * permanently unbookable: the row stays, so the index keeps refusing it,
     * while the slot picker cheerfully keeps offering it. Keying on
     * held_slot_at, which goes null when a booking lets go, is what lets both
     * of those things be true at once.
     */
    public function testADeclinedHourCanBeBookedAgainButALiveOneCannot(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $entityManager = $container->get(EntityManagerInterface::class);
        $trainers = $container->get(TrainerRepository::class);
        $users = $container->get(UserRepository::class);

        $trainer = $trainers->findOneActiveBySlug('ilze-berzina');
        self::assertNotNull($trainer);
        $member = $users->findOneByEmail('member@speks.lv');
        self::assertNotNull($member);
        $prospect = $users->findOneByEmail('prospect@speks.lv');
        self::assertNotNull($prospect);

        // An hour far enough out that no seeded booking is sitting on it.
        $start = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+90 days')
            ->setTime(11, 0);
        $end = $start->modify('+1 hour');

        $first = new Booking($trainer, $member, $start, $end);
        $entityManager->persist($first);
        $entityManager->flush();
        $firstId = $first->getId();
        self::assertNotNull($firstId);

        // While it is live, the same hour is refused by the index.
        $clash = new Booking($trainer, $prospect, $start, $end);
        $entityManager->persist($clash);

        try {
            $entityManager->flush();
            self::fail('A second live booking for the same hour should not reach the database.');
        } catch (UniqueConstraintViolationException) {
            // Exactly what the unique index is there for.
        }

        // A failed flush closes the entity manager, so carry on with a fresh one.
        $registry = $container->get('doctrine');
        $fresh = $registry->resetManager();
        self::assertInstanceOf(EntityManagerInterface::class, $fresh);

        $held = $fresh->find(Booking::class, $firstId);
        self::assertNotNull($held);
        self::assertNotNull($held->getHeldSlotAt());

        // The coach declines it. The hour goes back on sale.
        $held->decline();
        $fresh->flush();
        self::assertNull($held->getHeldSlotAt());

        $trainerAgain = $held->getTrainer();
        $prospectAgain = $fresh->find(User::class, $prospect->getId());
        self::assertNotNull($prospectAgain);

        $second = new Booking($trainerAgain, $prospectAgain, $start, $end);
        $fresh->persist($second);
        $fresh->flush();

        self::assertSame(BookingStatus::REQUESTED, $second->getStatus());
        self::assertNotNull($second->getId());

        // Leave the table as we found it.
        $fresh->remove($second);
        $fresh->remove($held);
        $fresh->flush();
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

    public function testACoachWithNoHoursSaysSoInsteadOfShowingAnEmptyPicker(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/en/trainers/deniss-petrovs/book');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'This coach has no open slots');
        self::assertCount(0, $crawler->filter('input[name="slot"]'));
    }

    /**
     * Anyone may look; only an account may ask.
     */
    public function testTheSlotPickerIsReadableSignedOutButOffersNoForm(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/en/trainers/ilze-berzina/book');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form[action="/en/trainers/ilze-berzina/book"]'));
        self::assertSelectorTextContains('body', 'Sign in to book');
    }

    public function testAnUnknownCoachHasNoSlotPage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/en/trainers/nobody/book');

        self::assertResponseStatusCodeSame(404);
    }

    public function testBookingWritesARequestSnapshotsThePriceAndWritesToBothPeople(): void
    {
        $client = static::createClient();
        $member = self::user('member@speks.lv');
        $client->loginUser($member);

        self::purge('artjoms-kuznecovs');

        [$token, $slot] = self::pickSlot($client, 'artjoms-kuznecovs');

        $client->request('POST', '/en/trainers/artjoms-kuznecovs/book', [
            '_token' => $token,
            'slot' => $slot,
            'notes' => 'Stuck at 100 kg on the bench.',
        ]);

        self::assertResponseRedirects('/en/account/bookings');

        // Asserted before following the redirect: the mailer's event collector
        // is reset by the next request, which is the M3 lesson.
        self::assertEmailCount(2);

        $booking = self::bookingAt('artjoms-kuznecovs', $slot);

        self::assertNotNull($booking);
        self::assertSame(BookingStatus::REQUESTED, $booking->getStatus());
        self::assertSame('Stuck at 100 kg on the bench.', $booking->getNotes());
        self::assertSame(60, $booking->getDurationMinutes());

        // Artjoms charges 35 euro an hour, and the booking remembers it rather
        // than reading it back through the trainer.
        self::assertSame(3500, $booking->getPricePaidCents());
    }

    /**
     * A coach with no login cannot be written to, and that must not stop the
     * booking being taken.
     */
    public function testBookingACoachWithNoAccountStillWritesToTheMember(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('prospect@speks.lv'));

        self::purge('ilze-berzina');

        [$token, $slot] = self::pickSlot($client, 'ilze-berzina');

        $client->request('POST', '/en/trainers/ilze-berzina/book', [
            '_token' => $token,
            'slot' => $slot,
        ]);

        self::assertResponseRedirects('/en/account/bookings');
        self::assertEmailCount(1);
        self::assertNotNull(self::bookingAt('ilze-berzina', $slot));
    }

    public function testTheSameSlotCannotBeBookedTwice(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('member@speks.lv'));

        self::purge('marta-ozola');

        [$token, $slot] = self::pickSlot($client, 'marta-ozola');

        $client->request('POST', '/en/trainers/marta-ozola/book', [
            '_token' => $token,
            'slot' => $slot,
        ]);
        self::assertResponseRedirects('/en/account/bookings');

        // Somebody else, the same hour.
        $client->loginUser(self::user('prospect@speks.lv'));
        [$token] = self::pickSlot($client, 'marta-ozola');

        $client->request('POST', '/en/trainers/marta-ozola/book', [
            '_token' => $token,
            'slot' => $slot,
        ]);

        // The slot is gone from the picker, so it is refused before the index
        // ever sees it - and the page is re-rendered rather than redirected.
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Somebody just took that slot');
    }

    public function testAPostWithoutAValidTokenBooksNothing(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('member@speks.lv'));

        self::purge('ilze-berzina');

        [, $slot] = self::pickSlot($client, 'ilze-berzina');

        $client->request('POST', '/en/trainers/ilze-berzina/book', [
            '_token' => 'not-the-token',
            'slot' => $slot,
        ]);

        self::assertResponseRedirects('/en/trainers/ilze-berzina/book');
        self::assertNull(self::bookingAt('ilze-berzina', $slot));
    }

    public function testAnHourInsideTheLeadTimeIsRefused(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('member@speks.lv'));

        [$token] = self::pickSlot($client, 'ilze-berzina');

        $client->request('POST', '/en/trainers/ilze-berzina/book', [
            '_token' => $token,
            // An hour that certainly is not on any coach's rota any more.
            'slot' => (new DateTimeImmutable('-1 day', new DateTimeZone('UTC')))->format('Y-m-d H:i'),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Somebody just took that slot');
    }

    public function testBookingIsClosedToAnonymousVisitors(): void
    {
        $client = static::createClient();
        $client->request('POST', '/en/trainers/ilze-berzina/book');

        self::assertResponseRedirects('http://localhost/en/login');
    }

    public function testTheAccountListsBookingsAndIsLinkedFromTheNav(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('member@speks.lv'));

        $crawler = $client->request('GET', '/en/account/bookings');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Your bookings');
        self::assertGreaterThan(0, $crawler->filter('a[href="/en/account/bookings"]')->count());
    }

    public function testAMemberCanCancelTheirOwnUpcomingSession(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('member@speks.lv'));

        self::purge('ilze-berzina');

        [$token, $slot] = self::pickSlot($client, 'ilze-berzina');
        $client->request('POST', '/en/trainers/ilze-berzina/book', [
            '_token' => $token,
            'slot' => $slot,
        ]);

        $booking = self::bookingAt('ilze-berzina', $slot);
        self::assertNotNull($booking);

        $crawler = $client->request('GET', '/en/account/bookings');
        $client->request('POST', '/en/account/bookings/'.$booking->getId().'/cancel', [
            '_token' => self::tokenIn($crawler),
        ]);

        self::assertResponseRedirects('/en/account/bookings');
        self::assertSame(BookingStatus::CANCELLED, self::refreshed($booking)->getStatus());
    }

    public function testAMemberCannotCancelSomebodyElsesSession(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('member@speks.lv'));

        self::purge('marta-ozola');

        [$token, $slot] = self::pickSlot($client, 'marta-ozola', 1);
        $client->request('POST', '/en/trainers/marta-ozola/book', [
            '_token' => $token,
            'slot' => $slot,
        ]);

        $booking = self::bookingAt('marta-ozola', $slot);
        self::assertNotNull($booking);

        // The prospect's own bookings page offers no cancel button for a
        // booking that is not theirs, so the token comes off a page that has
        // one - which is exactly what a forged request would do.
        $client->loginUser(self::user('prospect@speks.lv'));
        [$token] = self::pickSlot($client, 'ilze-berzina');

        $client->request('POST', '/en/account/bookings/'.$booking->getId().'/cancel', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/en/account/bookings');
        self::assertSame(BookingStatus::REQUESTED, self::refreshed($booking)->getStatus());

        $client->followRedirect();
        self::assertSelectorTextContains('body', 'That booking is not yours');
    }

    public function testASessionThatHasAlreadyHappenedCannotBeCancelled(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('member@speks.lv'));

        self::purge('deniss-petrovs');

        // Re-read after the purge: clearing the manager detaches everything it
        // was holding, and Doctrine would take the stale one for a new row.
        $member = self::user('member@speks.lv');
        $entityManager = self::entityManager();
        $trainer = self::trainers()->findOneActiveBySlug('deniss-petrovs');
        self::assertNotNull($trainer);

        $start = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->sub(new DateInterval('P3D'));
        $past = new Booking($trainer, $member, $start, $start->add(new DateInterval('PT1H')));
        $past->confirm();
        $entityManager->persist($past);
        $entityManager->flush();

        $crawler = $client->request('GET', '/en/account/bookings');
        $client->request('POST', '/en/account/bookings/'.$past->getId().'/cancel', [
            '_token' => self::tokenIn($crawler),
        ]);

        self::assertResponseRedirects('/en/account/bookings');
        self::assertSame(BookingStatus::CONFIRMED, self::refreshed($past)->getStatus());

        $client->followRedirect();
        self::assertSelectorTextContains('body', 'That session has already happened');
    }

    public function testCancellingWithoutAValidTokenChangesNothing(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('member@speks.lv'));

        self::purge('ilze-berzina');

        [$token, $slot] = self::pickSlot($client, 'ilze-berzina');
        $client->request('POST', '/en/trainers/ilze-berzina/book', [
            '_token' => $token,
            'slot' => $slot,
        ]);

        $booking = self::bookingAt('ilze-berzina', $slot);
        self::assertNotNull($booking);

        $client->request('GET', '/en/account/bookings');
        $client->request('POST', '/en/account/bookings/'.$booking->getId().'/cancel', [
            '_token' => 'not-the-token',
        ]);

        self::assertResponseRedirects('/en/account/bookings');
        self::assertSame(BookingStatus::REQUESTED, self::refreshed($booking)->getStatus());
    }

    /**
     * A GET of the picker, then the token and the first free slot it renders.
     *
     * Doubles as the preceding GET a synthesised POST needs: without one there
     * is no history, so BrowserKit sends no Referer and stateless CSRF has
     * nothing to check.
     *
     * @return array{string, string}
     */
    private static function pickSlot(KernelBrowser $client, string $slug, int $index = 0): array
    {
        $crawler = $client->request('GET', '/en/trainers/'.$slug.'/book');

        self::assertResponseIsSuccessful();

        $slots = $crawler->filter('input[name="slot"]');

        self::assertGreaterThan($index, $slots->count(), sprintf('No free slot %d rendered for "%s".', $index, $slug));

        return [self::tokenIn($crawler), $slots->eq($index)->attr('value') ?? ''];
    }

    private static function tokenIn(Crawler $crawler): string
    {
        $field = $crawler->filter('input[name="_token"]')->first();

        self::assertGreaterThan(0, $field->count(), 'The page rendered no CSRF token.');

        return $field->attr('value') ?? '';
    }

    /**
     * Wipes a coach's diary.
     *
     * The test database is seeded once and never rolled back between runs, so
     * a booking left by yesterday's suite would otherwise decide which hour
     * "the first free slot" means today. Each test that writes starts from a
     * coach with an empty week.
     */
    private static function purge(string $slug): void
    {
        $trainer = self::trainers()->findOneActiveBySlug($slug);
        self::assertNotNull($trainer);

        $entityManager = self::entityManager();
        $entityManager->createQuery('DELETE FROM App\Entity\Booking b WHERE b.trainer = :trainer')
            ->setParameter('trainer', $trainer)
            ->execute();
        $entityManager->clear();
    }

    private static function bookingAt(string $slug, string $slot): ?Booking
    {
        $trainer = self::trainers()->findOneActiveBySlug($slug);
        self::assertNotNull($trainer);

        $bookings = static::getContainer()->get(BookingRepository::class);
        self::assertInstanceOf(BookingRepository::class, $bookings);

        // Newest first: an hour may carry an older cancelled row as well, and
        // it is the one just written that a test is asking about.
        $found = $bookings->findBy(
            [
                'trainer' => $trainer,
                'startsAt' => new DateTimeImmutable($slot, new DateTimeZone('UTC')),
            ],
            ['id' => 'DESC'],
            1,
        );

        return $found[0] ?? null;
    }

    private static function refreshed(Booking $booking): Booking
    {
        $entityManager = self::entityManager();
        $entityManager->clear();

        $fresh = $entityManager->find(Booking::class, $booking->getId());
        self::assertInstanceOf(Booking::class, $fresh);

        return $fresh;
    }

    private static function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private static function trainers(): TrainerRepository
    {
        $trainers = static::getContainer()->get(TrainerRepository::class);
        self::assertInstanceOf(TrainerRepository::class, $trainers);

        return $trainers;
    }

    private static function user(string $email): User
    {
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);

        $user = $users->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
