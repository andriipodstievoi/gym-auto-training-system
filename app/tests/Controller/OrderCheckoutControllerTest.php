<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\Enum\OrderStatus;
use App\Entity\Order;
use App\Entity\Product;
use App\Entity\User;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;
use App\Repository\UserRepository;
use App\Tests\Payment\StripeTransportSpy;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Paying for a basket.
 *
 * No keys is the state this repository ships in and the only one CI can run,
 * so those tests come first: the refusal has to be clean, with an honest
 * message and no order row left behind.
 *
 * The handoff itself is reached by fabricating a key and replacing the SDK's
 * HTTP client with {@see StripeTransportSpy}. Nothing here touches Stripe -
 * there is no account, no key and no socket involved.
 */
final class OrderCheckoutControllerTest extends WebTestCase
{
    private const string CSRF_TOKEN = 'csrf-token';

    /**
     * What the stubbed Stripe answers with.
     */
    private const string STUB_SESSION = '{"id":"cs_test_order_handoff","object":"checkout.session","url":"https://checkout.stripe.test/c/pay/cs_test_order_handoff","payment_status":"unpaid"}';

    /**
     * The shaker's stock as the fixtures leave it, when a test has moved it.
     */
    private ?int $shakerStockToRestore = null;

    protected function tearDown(): void
    {
        $this->restoreFixtures();

        // The SDK's HTTP client is a global, so put the real one back rather
        // than leaving a stub behind for whatever runs next.
        ApiRequestor::setHttpClient(CurlClient::instance());

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

    /**
     * With no keys the checkout gives up before it ever looks at the basket,
     * so the empty-basket refusal only actually runs once keys exist.
     */
    public function testAnEmptyCartIsRefusedEvenWithKeysConfigured(): void
    {
        self::setStripeKeys();

        $client = static::createClient();
        $client->loginUser(self::user('prospect@speks.lv'));

        $client->request('GET', '/en/cart');
        $client->request('POST', '/en/shop/checkout', ['_token' => self::CSRF_TOKEN]);

        self::assertResponseRedirects('/en/cart');

        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Your cart is empty');

        self::assertSame([], self::orders()->findHistoryFor(self::user('prospect@speks.lv')));
    }

    public function testAPostWithABadTokenOrdersNothing(): void
    {
        self::setStripeKeys();

        $client = static::createClient();
        $client->loginUser(self::user('prospect@speks.lv'));

        self::addShakersToCart($client, 1);
        $client->request('POST', '/en/shop/checkout', ['_token' => 'not-the-token']);

        self::assertResponseRedirects('/en/cart');

        $client->followRedirect();
        self::assertSelectorTextContains('body', 'That form expired');

        self::assertSame([], self::orders()->findHistoryFor(self::user('prospect@speks.lv')));
    }

    /**
     * Something moved between the cart page and the button. The corrected
     * basket is shown rather than the old one being charged for.
     */
    public function testABasketThatMovedSinceTheCartPageIsShownAgainRatherThanCharged(): void
    {
        self::setStripeKeys();

        $client = static::createClient();
        $client->loginUser(self::user('prospect@speks.lv'));

        self::addShakersToCart($client, 3);

        // Two of the three sold while they were reading the page.
        $this->restockShaker(1);

        $client->request('POST', '/en/shop/checkout', ['_token' => self::CSRF_TOKEN]);

        self::assertResponseRedirects('/en/cart');

        $client->followRedirect();
        self::assertSelectorTextContains('body', 'We adjusted your cart');

        self::assertSame([], self::orders()->findHistoryFor(self::user('prospect@speks.lv')));
    }

    /**
     * The order is written before the handoff, because Stripe needs an id to
     * carry. When the handoff then fails nothing was charged, so the row has
     * to go - and the basket has to stay, because they still want the things
     * in it.
     */
    public function testWhenStripeRefusesTheSessionNoOrderIsLeftBehind(): void
    {
        self::setStripeKeys();
        ApiRequestor::setHttpClient(StripeTransportSpy::refusing());

        $client = static::createClient();
        $client->loginUser(self::user('prospect@speks.lv'));

        self::addShakersToCart($client, 2);
        $client->request('POST', '/en/shop/checkout', ['_token' => self::CSRF_TOKEN]);

        self::assertResponseRedirects('/en/cart');

        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('body', 'We could not start the payment');

        self::assertSame([], self::orders()->findHistoryFor(self::user('prospect@speks.lv')));

        // The basket survives a failure the member did not cause.
        self::assertCount(1, $crawler->filter('tbody tr'));
    }

    /**
     * The successful handoff, with Stripe stubbed out: a PENDING order priced
     * from the database, an emptied basket and a redirect to the page Stripe
     * returned. Nothing is marked paid here - only the webhook may do that.
     */
    public function testASuccessfulHandoffWritesAPendingOrderAndClearsTheBasket(): void
    {
        self::setStripeKeys();
        $transport = StripeTransportSpy::answering(self::STUB_SESSION);
        ApiRequestor::setHttpClient($transport);

        $client = static::createClient();
        $client->loginUser(self::user('prospect@speks.lv'));

        self::addShakersToCart($client, 2);
        $client->request('POST', '/en/shop/checkout', ['_token' => self::CSRF_TOKEN]);

        self::assertResponseRedirects('https://checkout.stripe.test/c/pay/cs_test_order_handoff');
        self::assertSame(1, $transport->calls);

        $order = self::orders()->findOneByCheckoutSession('cs_test_order_handoff');

        self::assertNotNull($order);
        self::assertSame(OrderStatus::PENDING, $order->getStatus());
        self::assertNull($order->getPaidAt());
        self::assertSame('prospect@speks.lv', $order->getEmail());
        self::assertCount(1, $order->getItems());

        // Priced from the catalogue this request read, not from the session.
        self::assertSame(2 * self::product('shaker')->getPriceCents(), $order->getTotalCents());

        // The basket became an order, so it is gone.
        $crawler = $client->request('GET', '/en/cart');
        self::assertCount(0, $crawler->filter('tbody tr'));
    }

    /**
     * A session with nowhere to send the member is no use, so it is treated as
     * a failed handoff rather than redirected to an empty string.
     */
    public function testASessionWithNoUrlIsTreatedAsAFailedHandoff(): void
    {
        self::setStripeKeys();
        ApiRequestor::setHttpClient(StripeTransportSpy::answering(
            '{"id":"cs_test_order_handoff_nourl","object":"checkout.session","url":null,"payment_status":"unpaid"}',
        ));

        $client = static::createClient();
        $client->loginUser(self::user('prospect@speks.lv'));

        self::addShakersToCart($client, 1);
        $client->request('POST', '/en/shop/checkout', ['_token' => self::CSRF_TOKEN]);

        self::assertResponseRedirects('/en/cart');

        $client->followRedirect();
        self::assertSelectorTextContains('body', 'We could not start the payment');

        // A handoff that produced no URL is a failed handoff, so it must clean
        // up after itself exactly as the exception path does. Leaving the row
        // would park a PENDING order in the member's account that nothing can
        // ever clear - they never reach the cancel route to do it.
        self::assertSame(
            [],
            self::orders()->findPendingFor(self::user('prospect@speks.lv')),
            'A session with no URL must not leave a pending order behind.',
        );
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

    private static function product(string $slug): Product
    {
        $repository = static::getContainer()->get(ProductRepository::class);
        self::assertInstanceOf(ProductRepository::class, $repository);

        $product = $repository->findOneBy(['slug' => $slug]);
        self::assertInstanceOf(Product::class, $product);

        return $product;
    }

    /**
     * The GET first is not decoration: a synthesised POST only carries a
     * Referer once the browser has some history, and stateless CSRF reads it.
     */
    private static function addShakersToCart(KernelBrowser $client, int $quantity): void
    {
        $client->request('GET', '/en/shop/p/shaker');
        $client->request('POST', '/en/cart/add', [
            '_token' => self::CSRF_TOKEN,
            'slug' => 'shaker',
            'quantity' => (string) $quantity,
        ]);
        $client->followRedirect();
    }

    /**
     * Moves the shaker's stock, noting the fixture value so tearDown can put
     * it back - the rest of the suite prices against it.
     */
    private function restockShaker(int $stock): void
    {
        $entityManager = self::entityManager();
        $product = self::product('shaker');

        $this->shakerStockToRestore ??= $product->getStock();
        $product->setStock($stock);

        $entityManager->flush();
    }

    private static function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    /**
     * The suite has no transactional rollback, so a handoff that got as far as
     * writing an order has to unwrite it, and a moved stock level has to go
     * back. The prospect owns nothing in the fixtures, which is what the tests
     * above rely on.
     */
    private function restoreFixtures(): void
    {
        $entityManager = self::entityManager();

        if (null !== $this->shakerStockToRestore) {
            self::product('shaker')->setStock($this->shakerStockToRestore);
            $entityManager->flush();
            $this->shakerStockToRestore = null;
        }

        // The lines go with it: customer_order_item is ON DELETE CASCADE.
        $entityManager->createQuery('DELETE FROM App\Entity\Order o WHERE o.user = :user')
            ->setParameter('user', self::user('prospect@speks.lv'))
            ->execute();

        $entityManager->clear();
    }

    private static function clearStripeKeys(): void
    {
        self::ensureKernelShutdown();

        foreach (['STRIPE_SECRET_KEY', 'STRIPE_PUBLIC_KEY', 'STRIPE_WEBHOOK_SECRET'] as $name) {
            $_ENV[$name] = '';
            $_SERVER[$name] = '';
        }
    }

    /**
     * Obviously fake keys. Nothing is ever sent with them: they exist so that
     * StripeCheckout::isConfigured() returns true and the request gets past
     * the guard, and the SDK's HTTP client is stubbed out for the calls that
     * would otherwise leave the machine.
     */
    private static function setStripeKeys(): void
    {
        self::ensureKernelShutdown();

        $_ENV['STRIPE_SECRET_KEY'] = $_SERVER['STRIPE_SECRET_KEY'] = 'sk_test_not_a_real_key';
        $_ENV['STRIPE_PUBLIC_KEY'] = $_SERVER['STRIPE_PUBLIC_KEY'] = 'pk_test_not_a_real_key';
    }
}
