<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\Enum\BookingStatus;
use App\Entity\Booking;
use App\Entity\Trainer;
use App\Entity\TrainerAvailability;
use App\Entity\User;
use App\Repository\TrainerAvailabilityRepository;
use App\Repository\TrainerRepository;
use App\Repository\UserRepository;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The coach area.
 *
 * There is no ROLE_TRAINER: every one of these proves that authorisation comes
 * from the data - coach@speks.lv is a coach because a trainer row points at
 * it, and prospect@speks.lv is not because none does.
 *
 * The bookings are written here rather than leaned on from the fixtures: the
 * test database is never rolled back, and a diary another test class has
 * already answered is not a diary this one can assert about.
 */
final class CoachControllerTest extends WebTestCase
{
    private const string COACH_SLUG = 'artjoms-kuznecovs';

    public function testTheCoachAreaIsClosedToAnonymousVisitors(): void
    {
        $client = static::createClient();
        $client->request('GET', '/en/coach');

        self::assertResponseRedirects('http://localhost/en/login');
    }

    public function testAnAccountThatNoTrainerPointsAtIsRefused(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('prospect@speks.lv'));

        $client->request('GET', '/en/coach');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/en/coach/availability');
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheDashboardShowsSessionsWaitingOnAnAnswer(): void
    {
        $client = static::createClient();
        self::purge(self::COACH_SLUG);
        self::seedBooking(self::COACH_SLUG, 'member@speks.lv', '+5 days 09:00');

        $client->loginUser(self::user('coach@speks.lv'));
        $client->request('GET', '/en/coach');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Your schedule');
        self::assertSelectorTextContains('body', 'Jānis Ozols');
        self::assertSelectorTextContains('body', 'Awaiting your answer');
    }

    public function testConfirmingASessionWritesToTheMember(): void
    {
        $client = static::createClient();
        self::purge(self::COACH_SLUG);
        $booking = self::seedBooking(self::COACH_SLUG, 'member@speks.lv', '+6 days 09:00');
        $id = $booking->getId();

        $client->loginUser(self::user('coach@speks.lv'));
        $crawler = $client->request('GET', '/en/coach');

        $client->request('POST', '/en/coach/bookings/'.$id.'/respond', [
            '_token' => self::tokenIn($crawler),
            'decision' => 'confirm',
        ]);

        self::assertResponseRedirects('/en/coach');
        self::assertEmailCount(1);
        self::assertSame(BookingStatus::CONFIRMED, self::statusOf($id));
    }

    public function testDecliningASessionWritesToTheMember(): void
    {
        $client = static::createClient();
        self::purge(self::COACH_SLUG);
        $id = self::seedBooking(self::COACH_SLUG, 'member@speks.lv', '+7 days 09:00')->getId();

        $client->loginUser(self::user('coach@speks.lv'));
        $crawler = $client->request('GET', '/en/coach');

        $client->request('POST', '/en/coach/bookings/'.$id.'/respond', [
            '_token' => self::tokenIn($crawler),
            'decision' => 'decline',
        ]);

        self::assertResponseRedirects('/en/coach');
        self::assertEmailCount(1);
        self::assertSame(BookingStatus::DECLINED, self::statusOf($id));
    }

    public function testASessionCanOnlyBeAnsweredOnce(): void
    {
        $client = static::createClient();
        self::purge(self::COACH_SLUG);
        $id = self::seedBooking(self::COACH_SLUG, 'member@speks.lv', '+8 days 09:00')->getId();

        $client->loginUser(self::user('coach@speks.lv'));
        $crawler = $client->request('GET', '/en/coach');
        $token = self::tokenIn($crawler);

        $client->request('POST', '/en/coach/bookings/'.$id.'/respond', [
            '_token' => $token,
            'decision' => 'confirm',
        ]);
        self::assertSame(BookingStatus::CONFIRMED, self::statusOf($id));

        // A second answer must not quietly overwrite the first. The buttons
        // are gone from the page by now, which is exactly why this is posted
        // by hand with the token the page handed out earlier.
        $client->request('GET', '/en/coach');
        $client->request('POST', '/en/coach/bookings/'.$id.'/respond', [
            '_token' => $token,
            'decision' => 'decline',
        ]);

        self::assertSame(BookingStatus::CONFIRMED, self::statusOf($id));
    }

    public function testACoachCannotAnswerForAnotherCoach(): void
    {
        $client = static::createClient();
        self::purge(self::COACH_SLUG);
        self::purge('ilze-berzina');

        // Something to hold a token, and somebody else's booking to aim at.
        self::seedBooking(self::COACH_SLUG, 'member@speks.lv', '+9 days 09:00');
        $foreign = self::seedBooking('ilze-berzina', 'prospect@speks.lv', '+9 days 10:00')->getId();

        $client->loginUser(self::user('coach@speks.lv'));
        $crawler = $client->request('GET', '/en/coach');

        $client->request('POST', '/en/coach/bookings/'.$foreign.'/respond', [
            '_token' => self::tokenIn($crawler),
            'decision' => 'confirm',
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(BookingStatus::REQUESTED, self::statusOf($foreign));
    }

    public function testRespondingWithoutAValidTokenChangesNothing(): void
    {
        $client = static::createClient();
        self::purge(self::COACH_SLUG);
        $id = self::seedBooking(self::COACH_SLUG, 'member@speks.lv', '+10 days 09:00')->getId();

        $client->loginUser(self::user('coach@speks.lv'));
        $client->request('GET', '/en/coach');

        $client->request('POST', '/en/coach/bookings/'.$id.'/respond', [
            '_token' => 'not-the-token',
            'decision' => 'confirm',
        ]);

        self::assertResponseRedirects('/en/coach');
        self::assertSame(BookingStatus::REQUESTED, self::statusOf($id));
    }

    public function testACoachCanAddAndRemoveWeeklyHours(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('coach@speks.lv'));

        $crawler = $client->request('GET', '/en/coach/availability');
        self::assertResponseIsSuccessful();

        $before = count(self::windows());

        $form = $crawler->selectButton('Add hours')->form();
        $form['trainer_availability_form[weekday]'] = '7';
        $form['trainer_availability_form[startTime]'] = '11';
        $form['trainer_availability_form[endTime]'] = '15';
        $client->submit($form);

        self::assertResponseRedirects('/en/coach/availability');
        $crawler = $client->followRedirect();
        self::assertCount($before + 1, self::windows());

        $after = self::windows();
        $added = end($after);
        self::assertInstanceOf(TrainerAvailability::class, $added);

        $client->request('POST', '/en/coach/availability/'.$added->getId().'/remove', [
            '_token' => self::tokenIn($crawler),
        ]);

        self::assertResponseRedirects('/en/coach/availability');
        self::assertCount($before, self::windows());
    }

    /**
     * An invalid submission answers 422, not 200.
     */
    public function testAWindowThatEndsBeforeItStartsIsRefused(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('coach@speks.lv'));

        $crawler = $client->request('GET', '/en/coach/availability');
        $before = count(self::windows());

        $form = $crawler->selectButton('Add hours')->form();
        $form['trainer_availability_form[weekday]'] = '7';
        $form['trainer_availability_form[startTime]'] = '18';
        $form['trainer_availability_form[endTime]'] = '09';
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertCount($before, self::windows());
    }

    public function testACoachCannotRemoveSomebodyElsesHours(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('coach@speks.lv'));

        $entityManager = self::entityManager();
        $ilze = self::trainers()->findOneActiveBySlug('ilze-berzina');
        self::assertNotNull($ilze);

        $window = (new TrainerAvailability($ilze))
            ->setWeekday(7)
            ->setStartTime(new DateTimeImmutable('06:00'))
            ->setEndTime(new DateTimeImmutable('07:00'));
        $entityManager->persist($window);
        $entityManager->flush();
        $id = $window->getId();

        $crawler = $client->request('GET', '/en/coach/availability');
        $client->request('POST', '/en/coach/availability/'.$id.'/remove', [
            '_token' => self::tokenIn($crawler),
        ]);

        self::assertResponseStatusCodeSame(404);

        $entityManager = self::entityManager();
        $survivor = $entityManager->find(TrainerAvailability::class, $id);
        self::assertInstanceOf(TrainerAvailability::class, $survivor);

        $entityManager->remove($survivor);
        $entityManager->flush();
    }

    /**
     * @return list<TrainerAvailability>
     */
    private static function windows(): array
    {
        self::entityManager()->clear();

        $trainer = self::trainers()->findOneActiveBySlug(self::COACH_SLUG);
        self::assertNotNull($trainer);

        $repository = static::getContainer()->get(TrainerAvailabilityRepository::class);
        self::assertInstanceOf(TrainerAvailabilityRepository::class, $repository);

        return $repository->findAllFor($trainer);
    }

    private static function seedBooking(string $slug, string $email, string $when): Booking
    {
        $entityManager = self::entityManager();

        $trainer = self::trainers()->findOneActiveBySlug($slug);
        self::assertNotNull($trainer);

        $start = new DateTimeImmutable($when, new DateTimeZone('UTC'));
        $booking = new Booking($trainer, self::user($email), $start, $start->add(new DateInterval('PT1H')));

        $entityManager->persist($booking);
        $entityManager->flush();

        return $booking;
    }

    private static function statusOf(?int $id): BookingStatus
    {
        $entityManager = self::entityManager();
        $entityManager->clear();

        $booking = $entityManager->find(Booking::class, $id);
        self::assertInstanceOf(Booking::class, $booking);

        return $booking->getStatus();
    }

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

    private static function tokenIn(Crawler $crawler): string
    {
        $field = $crawler->filter('input[name="_token"]')->first();

        self::assertGreaterThan(0, $field->count(), 'The page rendered no CSRF token.');

        return $field->attr('value') ?? '';
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
