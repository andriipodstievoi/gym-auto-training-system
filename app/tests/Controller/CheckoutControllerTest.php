<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\UserMembershipRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Checkout, with and without Stripe keys configured.
 *
 * Nothing here ever reaches Stripe. The unconfigured cases are the state this
 * repository actually ships in - no keys in .env, none in CI - and the
 * configured case only proves the page renders a buy button, never that a
 * session is created.
 */
final class CheckoutControllerTest extends WebTestCase
{
    /**
     * What csrf_token('checkout') actually renders. Stateless CSRF tokens are
     * validated against the request origin, and the placeholder value is only
     * swapped for a random one by the optional double-submit JavaScript.
     */
    private const string CSRF_TOKEN = 'csrf-token';

    protected function tearDown(): void
    {
        self::clearStripeKeys();
        parent::tearDown();
    }

    public function testTheMembershipPageRendersWithNoStripeKeysConfigured(): void
    {
        self::clearStripeKeys();

        $client = static::createClient();
        $crawler = $client->request('GET', '/en/memberships');

        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('article'));

        // No keys means no payment form anywhere on the page.
        self::assertCount(0, $crawler->filter('form'));
        self::assertSelectorTextContains('body', 'Checkout unavailable');
    }

    public function testStartingCheckoutWithNoKeysFailsSafely(): void
    {
        self::clearStripeKeys();

        $client = static::createClient();
        $client->loginUser(self::user('prospect@speks.lv'));

        $client->request('GET', '/en/memberships');
        $client->request('POST', '/en/memberships/all-branches/checkout', [
            '_token' => self::CSRF_TOKEN,
        ]);

        self::assertResponseRedirects('/en/memberships');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Card payment is not available right now');

        // Nothing was written: an abandoned attempt must not leave a row.
        self::assertSame([], self::membershipRepository()->findPendingFor(self::user('prospect@speks.lv')));
    }

    public function testCheckoutIsClosedToAnonymousVisitors(): void
    {
        self::clearStripeKeys();

        $client = static::createClient();
        $client->request('POST', '/en/memberships/all-branches/checkout');

        self::assertResponseRedirects('http://localhost/en/login');
    }

    public function testAnUnknownPlanIsNotFound(): void
    {
        self::setStripeKeys();

        $client = static::createClient();
        $client->loginUser(self::user('prospect@speks.lv'));

        $client->request('GET', '/en/memberships');
        $client->request('POST', '/en/memberships/no-such-plan/checkout', [
            '_token' => self::CSRF_TOKEN,
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testWithKeysConfiguredTheSignedInMemberGetsABuyButton(): void
    {
        self::setStripeKeys();

        $client = static::createClient();
        $client->loginUser(self::user('prospect@speks.lv'));

        $crawler = $client->request('GET', '/en/memberships');

        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('form[method="post"]'));
        self::assertStringContainsString('/en/memberships/all-branches/checkout', $client->getResponse()->getContent() ?: '');
    }

    public function testAMemberWhoAlreadyPaidIsNotSoldASecondMembership(): void
    {
        self::setStripeKeys();

        $client = static::createClient();
        $client->loginUser(self::user('member@speks.lv'));

        $crawler = $client->request('GET', '/en/memberships');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form[method="post"]'));
        self::assertSelectorTextContains('body', 'Your current membership');
    }

    public function testAnonymousVisitorsAreInvitedToSignInRatherThanToPay(): void
    {
        self::setStripeKeys();

        $client = static::createClient();
        $crawler = $client->request('GET', '/en/memberships');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form[method="post"]'));
        self::assertSelectorTextContains('body', 'Sign in to join');
    }

    /**
     * A checkout session id nobody owns must not reveal anything.
     */
    public function testTheSuccessPageIgnoresAnUnknownSession(): void
    {
        self::clearStripeKeys();

        $client = static::createClient();
        $client->loginUser(self::user('prospect@speks.lv'));

        $client->request('GET', '/en/account/checkout/success?session_id=cs_test_not_a_real_session');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'could not match that payment');
    }

    /**
     * Another member's purchase must never be shown, even with the right id.
     */
    public function testTheSuccessPageWillNotShowSomebodyElsesPurchase(): void
    {
        self::clearStripeKeys();

        $client = static::createClient();
        $client->loginUser(self::user('prospect@speks.lv'));

        // This session id belongs to member@speks.lv in the fixtures.
        $client->request('GET', '/en/account/checkout/success?session_id=cs_test_fixture_member');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'could not match that payment');
    }

    private static function user(string $email): User
    {
        $repository = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);

        $user = $repository->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private static function membershipRepository(): UserMembershipRepository
    {
        $repository = static::getContainer()->get(UserMembershipRepository::class);
        self::assertInstanceOf(UserMembershipRepository::class, $repository);

        return $repository;
    }

    /**
     * The state this repository ships in: no keys anywhere.
     */
    private static function clearStripeKeys(): void
    {
        self::ensureKernelShutdown();

        foreach (['STRIPE_SECRET_KEY', 'STRIPE_PUBLIC_KEY', 'STRIPE_WEBHOOK_SECRET'] as $name) {
            $_ENV[$name] = '';
            $_SERVER[$name] = '';
        }
    }

    /**
     * Obviously fake keys. They are never sent anywhere - they exist only so
     * StripeCheckout::isConfigured() returns true and the template renders the
     * branch a real installation would show.
     */
    private static function setStripeKeys(): void
    {
        self::ensureKernelShutdown();

        $_ENV['STRIPE_SECRET_KEY'] = $_SERVER['STRIPE_SECRET_KEY'] = 'sk_test_not_a_real_key';
        $_ENV['STRIPE_PUBLIC_KEY'] = $_SERVER['STRIPE_PUBLIC_KEY'] = 'pk_test_not_a_real_key';
    }
}
