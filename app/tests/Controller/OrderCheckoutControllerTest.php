<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Order;
use App\Entity\User;
use App\Repository\OrderRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Paying for a basket, with no Stripe keys configured.
 *
 * That is the state this repository ships in and the only one CI can run, so
 * the tests prove the refusal is clean: an honest message and, crucially, no
 * order row left behind.
 */
final class OrderCheckoutControllerTest extends WebTestCase
{
    private const string CSRF_TOKEN = 'csrf-token';

    protected function tearDown(): void
    {
        self::clearStripeKeys();
        parent::tearDown();
    }

    public function testCheckoutIsClosedToAnonymousVisitors(): void
    {
        self::clearStripeKeys();

        $client = static::createClient();
        $client->request('POST', '/en/shop/checkout');

        self::assertResponseRedirects('http://localhost/en/login');
    }

    public function testCheckoutWithNoKeysFailsSafelyAndCreatesNoOrder(): void
    {
        self::clearStripeKeys();

        $client = static::createClient();
        $client->loginUser(self::user('prospect@speks.lv'));

        $client->request('GET', '/en/shop/p/shaker');
        $client->request('POST', '/en/cart/add', [
            '_token' => self::CSRF_TOKEN,
            'slug' => 'shaker',
            'quantity' => '1',
        ]);
        $client->followRedirect();

        $client->request('POST', '/en/shop/checkout', ['_token' => self::CSRF_TOKEN]);

        self::assertResponseRedirects('/en/cart');

        // The prospect has never bought anything and still has not.
        self::assertSame([], self::orders()->findHistoryFor(self::user('prospect@speks.lv')));
    }

    public function testCheckoutWithAnEmptyCartCreatesNoOrder(): void
    {
        self::clearStripeKeys();

        $client = static::createClient();
        $client->loginUser(self::user('prospect@speks.lv'));

        $client->request('GET', '/en/cart');
        $client->request('POST', '/en/shop/checkout', ['_token' => self::CSRF_TOKEN]);

        self::assertResponseRedirects('/en/cart');
        self::assertSame([], self::orders()->findHistoryFor(self::user('prospect@speks.lv')));
    }

    public function testTheCartPageOffersNoCheckoutButtonWithoutKeys(): void
    {
        self::clearStripeKeys();

        $client = static::createClient();
        $client->loginUser(self::user('prospect@speks.lv'));

        $client->request('GET', '/en/shop/p/shaker');
        $client->request('POST', '/en/cart/add', [
            '_token' => self::CSRF_TOKEN,
            'slug' => 'shaker',
            'quantity' => '1',
        ]);
        $crawler = $client->followRedirect();

        self::assertCount(0, $crawler->filter('form[action="/en/shop/checkout"]'));
    }

    /**
     * Uses the prospect rather than the seeded member, because cancelling
     * deletes pending orders and the member's fixture order is one.
     */
    public function testCancellingDropsPendingOrdersButKeepsTheBasket(): void
    {
        self::clearStripeKeys();

        $client = static::createClient();
        $prospect = self::user('prospect@speks.lv');
        $client->loginUser($prospect);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        // A handoff to Stripe that was never paid for.
        $entityManager->persist(new Order($prospect));
        $entityManager->flush();

        self::assertCount(1, self::orders()->findPendingFor($prospect));

        $client->request('GET', '/en/shop/p/shaker');
        $client->request('POST', '/en/cart/add', [
            '_token' => self::CSRF_TOKEN,
            'slug' => 'shaker',
            'quantity' => '1',
        ]);
        $client->followRedirect();

        $client->request('GET', '/en/shop/checkout/cancel');
        self::assertResponseRedirects('/en/cart');

        $crawler = $client->followRedirect();

        // The basket survives - they backed out of paying, not out of wanting.
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertSame([], self::orders()->findPendingFor(self::user('prospect@speks.lv')));
    }

    public function testTheSuccessPageIgnoresAnUnknownSession(): void
    {
        self::clearStripeKeys();

        $client = static::createClient();
        $client->loginUser(self::user('prospect@speks.lv'));

        $crawler = $client->request('GET', '/en/shop/checkout/success?session_id=cs_test_not_a_real_session');

        self::assertResponseIsSuccessful();
        // The page renders, but only as the "we cannot find it" branch.
        self::assertStringNotContainsString('SPK-', $crawler->filter('body')->text());
        self::assertCount(0, $crawler->filter('dl'));
    }

    public function testTheSuccessPageWillNotShowSomebodyElsesOrder(): void
    {
        self::clearStripeKeys();

        $client = static::createClient();
        $client->loginUser(self::user('prospect@speks.lv'));

        // This session id belongs to member@speks.lv in the fixtures.
        $crawler = $client->request('GET', '/en/shop/checkout/success?session_id=cs_test_fixture_order_paid');

        self::assertResponseIsSuccessful();

        // The order exists, but it is not theirs, so no detail block renders.
        self::assertCount(0, $crawler->filter('dl'));
    }

    public function testTheSuccessPageShowsTheMembersOwnOrder(): void
    {
        self::clearStripeKeys();

        $client = static::createClient();
        $client->loginUser(self::user('member@speks.lv'));

        $crawler = $client->request('GET', '/en/shop/checkout/success?session_id=cs_test_fixture_order_paid');

        self::assertResponseIsSuccessful();

        $order = self::orders()->findOneByCheckoutSession('cs_test_fixture_order_paid');
        self::assertNotNull($order);
        self::assertStringContainsString($order->getReference(), $crawler->filter('body')->text());
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

    private static function clearStripeKeys(): void
    {
        self::ensureKernelShutdown();

        foreach (['STRIPE_SECRET_KEY', 'STRIPE_PUBLIC_KEY', 'STRIPE_WEBHOOK_SECRET'] as $name) {
            $_ENV[$name] = '';
            $_SERVER[$name] = '';
        }
    }
}
