<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\Enum\BookingStatus;
use App\Entity\Booking;
use App\Entity\User;
use App\Repository\TrainerRepository;
use App\Repository\UserRepository;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The booking corner of the back office.
 *
 * Requested rather than constructed, for the reason {@see AdminShopCrudTest}
 * records: EasyAdmin's failures are runtime ones, and a custom action missing
 * its #[AdminRoute] is a 500 on a page PHPStan is perfectly happy with.
 */
final class AdminBookingCrudTest extends WebTestCase
{
    public function testTheBookingCrudPagesRender(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('admin@speks.lv'));

        foreach (['/admin/booking', '/admin/trainer-availability', '/admin/trainer-availability/new'] as $url) {
            $client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }
    }

    /**
     * Nobody hand-writes a session into existence, the same rule orders have.
     */
    public function testStaffCannotInventABooking(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('admin@speks.lv'));

        $client->request('GET', '/admin/booking/new');

        self::assertResponseStatusCodeSame(403);
    }

    public function testStaffCanArchiveAConfirmedSessionThatHasPassed(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('admin@speks.lv'));

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $trainers = static::getContainer()->get(TrainerRepository::class);
        self::assertInstanceOf(TrainerRepository::class, $trainers);

        $trainer = $trainers->findOneActiveBySlug('deniss-petrovs');
        self::assertNotNull($trainer);

        $start = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->sub(new DateInterval('P30D'));
        $booking = new Booking($trainer, self::user('member@speks.lv'), $start, $start->add(new DateInterval('PT1H')));
        $booking->confirm();
        $entityManager->persist($booking);
        $entityManager->flush();
        $id = $booking->getId();

        $client->request('GET', '/admin/booking/'.$id.'/complete');
        self::assertResponseRedirects();

        $entityManager->clear();
        $archived = $entityManager->find(Booking::class, $id);
        self::assertInstanceOf(Booking::class, $archived);
        self::assertSame(BookingStatus::COMPLETED, $archived->getStatus());

        $entityManager->remove($archived);
        $entityManager->flush();
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
