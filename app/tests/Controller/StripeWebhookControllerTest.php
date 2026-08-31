<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\Enum\MembershipStatus;
use App\Repository\UserMembershipRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The webhook is a public endpoint, so its signature check is its whole
 * security. These tests exist to prove it cannot be talked out of it.
 */
final class StripeWebhookControllerTest extends WebTestCase
{
    private const string PAYLOAD = '{"id":"evt_test","type":"checkout.session.completed"}';

    protected function tearDown(): void
    {
        self::clearWebhookSecret();
        parent::tearDown();
    }

    public function testWithNoSigningSecretTheEndpointRefusesEverything(): void
    {
        self::clearWebhookSecret();

        $client = static::createClient();
        $client->request('POST', '/webhook/stripe', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: self::PAYLOAD);

        // 503, not 200: an unverifiable webhook is a misconfiguration, and
        // Stripe should keep retrying rather than consider it delivered.
        self::assertResponseStatusCodeSame(503);
    }

    public function testAnUnsignedPayloadIsRejected(): void
    {
        self::setWebhookSecret();

        $client = static::createClient();
        $client->request('POST', '/webhook/stripe', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: self::PAYLOAD);

        self::assertResponseStatusCodeSame(400);
    }

    public function testAForgedSignatureIsRejected(): void
    {
        self::setWebhookSecret();

        $client = static::createClient();
        $client->request('POST', '/webhook/stripe', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't=1700000000,v1=deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef',
        ], content: self::PAYLOAD);

        self::assertResponseStatusCodeSame(400);
    }

    /**
     * The whole point of the signature check: a POST that simply claims a
     * payment happened must not activate anything.
     */
    public function testAForgedPaymentDoesNotActivateAMembership(): void
    {
        self::setWebhookSecret();

        $client = static::createClient();
        $client->request('POST', '/webhook/stripe', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't=1700000000,v1=0000000000000000000000000000000000000000000000000000000000000000',
        ], content: '{"id":"evt_forged","type":"checkout.session.completed","data":{"object":{"id":"cs_test_forged","object":"checkout.session","payment_status":"paid"}}}');

        self::assertResponseStatusCodeSame(400);

        $repository = static::getContainer()->get(UserMembershipRepository::class);
        self::assertInstanceOf(UserMembershipRepository::class, $repository);
        self::assertNull($repository->findOneByCheckoutSession('cs_test_forged'));
    }

    public function testTheEndpointIsNotBehindTheLoginWall(): void
    {
        self::clearWebhookSecret();

        $client = static::createClient();
        $client->request('POST', '/webhook/stripe', content: self::PAYLOAD);

        // Stripe cannot sign in, so anything but a redirect to /login is right.
        self::assertResponseStatusCodeSame(503);
    }

    public function testGetIsNotRouted(): void
    {
        self::setWebhookSecret();

        $client = static::createClient();
        $client->request('GET', '/webhook/stripe');

        self::assertResponseStatusCodeSame(405);
    }

    /**
     * The seeded membership must survive every rejected webhook above.
     */
    public function testTheSeededMembershipIsUntouched(): void
    {
        self::clearWebhookSecret();

        $client = static::createClient();
        $client->request('POST', '/webhook/stripe', content: self::PAYLOAD);

        $repository = static::getContainer()->get(UserMembershipRepository::class);
        self::assertInstanceOf(UserMembershipRepository::class, $repository);

        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);

        $membership = $repository->findOneByCheckoutSession('cs_test_fixture_member');
        self::assertNotNull($membership);
        self::assertSame(MembershipStatus::ACTIVE, $membership->getStatus());
    }

    private static function setWebhookSecret(): void
    {
        self::ensureKernelShutdown();
        $_ENV['STRIPE_WEBHOOK_SECRET'] = $_SERVER['STRIPE_WEBHOOK_SECRET'] = 'whsec_not_a_real_secret';
    }

    private static function clearWebhookSecret(): void
    {
        self::ensureKernelShutdown();
        $_ENV['STRIPE_WEBHOOK_SECRET'] = $_SERVER['STRIPE_WEBHOOK_SECRET'] = '';
    }
}
