<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\Enum\OrderStatus;
use App\Entity\Order;
use App\Entity\User;
use App\Repository\OrderRepository;
use App\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The shop's corner of the back office.
 *
 * EasyAdmin fails at runtime rather than at compile time - a custom action
 * without #[AdminRoute] is a 500 on a page that PHPStan is perfectly happy
 * with - so the pages get requested rather than merely constructed.
 *
 * The fulfil test uses an order it creates itself, so it never rewrites the
 * fixtures other tests read.
 */
final class AdminShopCrudTest extends WebTestCase
{
    public function testTheShopCrudPagesRender(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('admin@speks.lv'));

        foreach (['/admin/order', '/admin/product-variant', '/admin/product-variant/new', '/admin/product'] as $url) {
            $client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }
    }

    public function testStaffCanMoveAPaidOrderToFulfilledButCannotInventOne(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('admin@speks.lv'));

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $order = new Order(self::user('prospect@speks.lv'));
        $order->markPaid(new DateTimeImmutable('-1 day'));
        $entityManager->persist($order);
        $entityManager->flush();

        $id = $order->getId();
        self::assertIsInt($id);

        $client->request('GET', '/admin/order/'.$id);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/admin/order/'.$id.'/fulfil');
        self::assertResponseRedirects();

        self::assertSame(OrderStatus::FULFILLED, self::orders()->find($id)?->getStatus());

        // NEW is disabled, not merely hidden: the URL refuses too, so staff
        // cannot hand-type a sale into existence.
        $client->request('GET', '/admin/order/new');
        self::assertResponseStatusCodeSame(403);

        // Take the throwaway order back out, so the rest of the suite still
        // sees the prospect as somebody who has never bought anything. The
        // client reboots the kernel between requests, so the manager fetched
        // at the top of this test is no longer the live one.
        $current = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $current);

        $throwaway = self::orders()->find($id);
        self::assertInstanceOf(Order::class, $throwaway);

        $current->remove($throwaway);
        $current->flush();
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
