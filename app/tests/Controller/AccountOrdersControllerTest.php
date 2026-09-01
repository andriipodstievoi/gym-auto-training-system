<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\OrderRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The member's order history. Expects a seeded test database.
 */
final class AccountOrdersControllerTest extends WebTestCase
{
    public function testTheMemberSeesTheirOwnOrdersNewestFirst(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('member@speks.lv'));

        $crawler = $client->request('GET', '/en/account/orders');

        self::assertResponseIsSuccessful();

        // One paid and one pending, from OrderFixtures.
        self::assertCount(2, $crawler->filter('tbody tr'));

        $text = $crawler->filter('body')->text();

        foreach (self::orders()->findHistoryFor(self::user('member@speks.lv')) as $order) {
            self::assertStringContainsString($order->getReference(), $text);
        }

        // 45.00 hoodie + 2 x 9.00 shaker.
        self::assertStringContainsString('63.00', $text);
    }

    public function testAMemberWithoutOrdersIsToldSo(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('prospect@speks.lv'));

        $crawler = $client->request('GET', '/en/account/orders');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('table'));
    }

    public function testTheOrdersPageIsClosedToAnonymousVisitors(): void
    {
        $client = static::createClient();
        $client->request('GET', '/en/account/orders');

        self::assertResponseRedirects('http://localhost/en/login');
    }

    private static function user(string $email): User
    {
        $repository = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);

        $user = $repository->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private static function orders(): OrderRepository
    {
        $repository = static::getContainer()->get(OrderRepository::class);
        self::assertInstanceOf(OrderRepository::class, $repository);

        return $repository;
    }
}
