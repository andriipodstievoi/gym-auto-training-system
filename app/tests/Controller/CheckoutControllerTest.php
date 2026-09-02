<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\Enum\MembershipStatus;
use App\Entity\User;
use App\Repository\UserMembershipRepository;
use App\Repository\UserRepository;
use App\Tests\Payment\StripeTransportSpy;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Checkout, with and without Stripe keys configured.
 *
 * The unconfigured cases are the state this repository actually ships in - no
 * keys in .env, none in CI - and they are the ones that matter most.
 *
 * Nothing here reaches Stripe either. The handoff itself is exercised by
 * fabricating a key and replacing the SDK's HTTP client with
 * {@see StripeTransportSpy}, so the branch that leaves no pending row behind
 * when Stripe refuses gets to run without an account or a socket.
 */
final class CheckoutControllerTest extends WebTestCase
{
    /**
     * What csrf_token('checkout') actually renders. Stateless CSRF tokens are
     * validated against the request origin, and the placeholder value is only
     * swapped for a random one by the optional double-submit JavaScript.
     */
    private const string CSRF_TOKEN = 'csrf-token';

    /**
     * What the stubbed Stripe answers with. The id is distinctive so tearDown
     * can recognise anything it left behind.
     */
    private const string STUB_SESSION = '{"id":"cs_test_handoff","object":"checkout.session","url":"https://checkout.stripe.test/c/pay/cs_test_handoff","payment_status":"unpaid"}';

    protected function tearDown(): void
    {
        self::purgeProspectMemberships();

        // The SDK's HTTP client is a global, so put the real one back rather
        // than leaving a stub behind for whatever runs next.
        ApiRequestor::setHttpClient(CurlClient::instance());

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

    /**
     * Hiding the button is not the same as refusing the purchase: the POST is
     * reachable by hand, and it has to say no on its own.
     */
    public function testACurrentMemberPostingCheckoutDirectlyIsRefused(): void
    {
        self::setStripeKeys();

        $client = static::createClient();
        $member = self::user('member@speks.lv');
        $client->loginUser($member);

        $client->request('GET', '/en/memberships');
        $client->request('POST', '/en/memberships/all-branches/checkout', [
            '_token' => self::CSRF_TOKEN,
        ]);

        self::assertResponseRedirects('/en/account');

        $client->followRedirect();
        self::assertSelectorTextContains('body', 'You already have an active membership');

        // Refused before anything was written, so no second row exists.
        self::assertSame([], self::membershipRepository()->findPendingFor(self::user('member@speks.lv')));
    }

    public function testAPostWithABadTokenBuysNothing(): void
    {
        self::setStripeKeys();

        $client = static::createClient();
        $client->loginUser(self::user('prospect@speks.lv'));

        $client->request('GET', '/en/memberships');
        $client->request('POST', '/en/memberships/all-branches/checkout', [
            '_token' => 'not-the-token',
        ]);

        self::assertResponseRedirects('/en/memberships');

        $client->followRedirect();
        self::assertSelectorTextContains('body', 'That form expired');

        self::assertSame([], self::membershipRepository()->findPendingFor(self::user('prospect@speks.lv')));
    }

    /**
     * The row is written before the handoff, because Stripe needs an id to
     * carry. When the handoff then fails, nothing was charged - so the row has
     * to go rather than sit on the member's account page forever.
     */
    public function testWhenStripeRefusesTheSessionNoPendingRowIsLeftBehind(): void
    {
        self::setStripeKeys();
        ApiRequestor::setHttpClient(StripeTransportSpy::refusing());

        $client = static::createClient();
        $client->loginUser(self::user('prospect@speks.lv'));

        $client->request('GET', '/en/memberships');
        $client->request('POST', '/en/memberships/all-branches/checkout', [
            '_token' => self::CSRF_TOKEN,
        ]);

        self::assertResponseRedirects('/en/memberships');

        $client->followRedirect();
        self::assertSelectorTextContains('body', 'We could not start the payment');

        self::assertSame([], self::membershipRepository()->findHistoryFor(self::user('prospect@speks.lv')));
    }

    /**
     * The successful handoff, with Stripe stubbed out: a PENDING row carrying
     * the session id, and a redirect to the page Stripe returned. Nothing is
     * activated here - only the webhook may do that.
     */
    public function testASuccessfulHandoffWritesAPendingRowAndRedirectsToStripe(): void
    {
        self::setStripeKeys();
        $transport = StripeTransportSpy::answering(self::STUB_SESSION);
        ApiRequestor::setHttpClient($transport);

        $client = static::createClient();
        $client->loginUser(self::user('prospect@speks.lv'));

        $client->request('GET', '/en/memberships');
        $client->request('POST', '/en/memberships/all-branches/checkout', [
            '_token' => self::CSRF_TOKEN,
        ]);

        self::assertResponseRedirects('https://checkout.stripe.test/c/pay/cs_test_handoff');
        self::assertSame(1, $transport->calls);

        $membership = self::membershipRepository()->findOneByCheckoutSession('cs_test_handoff');

        self::assertNotNull($membership);
        self::assertSame(MembershipStatus::PENDING, $membership->getStatus());
        self::assertSame('prospect@speks.lv', $membership->getUser()->getEmail());

        // The price was copied off the plan at purchase, not read through it.
        self::assertSame($membership->getPlan()->getPriceCents(), $membership->getPricePaidCents());
    }

    /**
     * A session with nowhere to send the member is no use, so it is treated as
     * a failed handoff rather than redirected to an empty string.
     */
    public function testASessionWithNoUrlIsTreatedAsAFailedHandoff(): void
    {
        self::setStripeKeys();
        ApiRequestor::setHttpClient(StripeTransportSpy::answering(
            '{"id":"cs_test_handoff_nourl","object":"checkout.session","url":null,"payment_status":"unpaid"}',
        ));

        $client = static::createClient();
        $client->loginUser(self::user('prospect@speks.lv'));

        $client->request('GET', '/en/memberships');
        $client->request('POST', '/en/memberships/all-branches/checkout', [
            '_token' => self::CSRF_TOKEN,
        ]);

        self::assertResponseRedirects('/en/memberships');

        $client->followRedirect();
        self::assertSelectorTextContains('body', 'We could not start the payment');

        // Same reasoning as the order side: a handoff with no URL is a failed
        // handoff and must not park a PENDING membership in the account.
        self::assertSame(
            [],
            self::membershipRepository()->findPendingFor(self::user('prospect@speks.lv')),
            'A session with no URL must not leave a pending membership behind.',
        );
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
     * The member backed out on Stripe's page. The row was written before the
     * handoff, so it has to be dropped rather than left on their account as a
     * purchase that never happened.
     */
    public function testCancellingDropsThePendingRow(): void
    {
        self::setStripeKeys();
        $transport = StripeTransportSpy::answering(self::STUB_SESSION);
        ApiRequestor::setHttpClient($transport);

        $client = static::createClient();
        $client->loginUser(self::user('prospect@speks.lv'));

        $client->request('GET', '/en/memberships');
        $client->request('POST', '/en/memberships/all-branches/checkout', [
            '_token' => self::CSRF_TOKEN,
        ]);

        self::assertCount(1, self::membershipRepository()->findPendingFor(self::user('prospect@speks.lv')));

        $client->request('GET', '/en/account/checkout/cancel');

        self::assertResponseRedirects('/en/memberships');

        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Payment cancelled');

        self::assertSame([], self::membershipRepository()->findHistoryFor(self::user('prospect@speks.lv')));
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
     * The suite has no transactional rollback, so a handoff that got as far as
     * writing a row has to unwrite it. The prospect owns nothing in the
     * fixtures, which is what the tests above rely on.
     */
    private static function purgeProspectMemberships(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $entityManager->createQuery('DELETE FROM App\Entity\UserMembership m WHERE m.user = :user')
            ->setParameter('user', self::user('prospect@speks.lv'))
            ->execute();

        $entityManager->clear();
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
