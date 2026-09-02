<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\Enum\MembershipStatus;
use App\Domain\Enum\OrderStatus;
use App\Domain\TranslatedString;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\User;
use App\Entity\UserMembership;
use App\Repository\MembershipPlanRepository;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;
use App\Repository\UserMembershipRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The webhook is a public endpoint, so its signature check is its whole
 * security. These tests exist to prove it cannot be talked out of it - and,
 * once it is satisfied, that the one place money is recognised behaves.
 *
 * Nothing here touches Stripe. The signing secret is a fabricated string put
 * into the environment before the kernel boots, and every payload is signed
 * with it exactly the way Stripe signs one: HMAC-SHA256 over
 * "<timestamp>.<body>", presented as "t=...,v1=...".
 *
 * Every row these tests write carries {@see SESSION_PREFIX} so tearDown can
 * find it again; the suite has no transactional rollback and the fixtures have
 * to survive the file.
 */
final class StripeWebhookControllerTest extends WebTestCase
{
    private const string PAYLOAD = '{"id":"evt_test","type":"checkout.session.completed"}';

    /**
     * Fabricated, and never sent anywhere. It exists so a signature can be
     * computed on this side of the wire.
     */
    private const string SIGNING_SECRET = 'whsec_not_a_real_secret';

    /**
     * Marks every checkout session id this file invents, so the rows written
     * against them can be found and deleted again.
     */
    private const string SESSION_PREFIX = 'cs_test_hook_';

    /**
     * Fixture stock levels to put back, keyed by product slug or variant SKU.
     *
     * @var array<string, int>
     */
    private array $stockToRestore = [];

    protected function tearDown(): void
    {
        $this->restoreFixtures();
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

    /**
     * A payment for a real pending row, arriving at an endpoint with no secret
     * to check it against, must still change nothing.
     */
    public function testWithNoSigningSecretNothingIsPromoted(): void
    {
        self::setWebhookSecret();
        static::createClient();
        $this->seedPendingMembership('unverifiable');

        self::clearWebhookSecret();
        $client = static::createClient();

        $payload = self::sessionEvent('checkout.session.completed', 'unverifiable');
        $client->request('POST', '/webhook/stripe', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => self::sign($payload),
        ], content: $payload);

        self::assertResponseStatusCodeSame(503);
        self::assertEmailCount(0);
        self::assertSame(MembershipStatus::PENDING, self::membership('unverifiable')->getStatus());
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

    /**
     * Signing one body and posting another is the attack the header exists to
     * stop, and it has to be stopped for a session that would otherwise be
     * promoted.
     */
    public function testASignatureLiftedFromAnotherPayloadIsRejected(): void
    {
        self::setWebhookSecret();

        $client = static::createClient();
        $this->seedPendingMembership('lifted');

        $signed = self::sessionEvent('checkout.session.completed', 'lifted', paymentStatus: 'unpaid');
        $posted = str_replace('"unpaid"', '"paid"', $signed);
        self::assertNotSame($signed, $posted);

        $client->request('POST', '/webhook/stripe', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => self::sign($signed),
        ], content: $posted);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(MembershipStatus::PENDING, self::membership('lifted')->getStatus());
    }

    /**
     * Signed by us, in date, and still not JSON. A signature says who sent the
     * body, not that the body means anything.
     */
    public function testAnUnparseableBodyIsRejected(): void
    {
        self::setWebhookSecret();

        $client = static::createClient();
        self::post($client, 'this is not JSON at all');

        self::assertResponseStatusCodeSame(400);
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

    /**
     * Some payment methods settle after the redirect and send an unpaid
     * "completed" event first. Nothing may be promoted on the strength of it.
     */
    public function testACompletedButUnpaidSessionPromotesNothing(): void
    {
        self::setWebhookSecret();

        $client = static::createClient();
        $this->seedPendingMembership('unpaid');

        self::post($client, self::sessionEvent('checkout.session.completed', 'unpaid', paymentStatus: 'unpaid'));

        self::assertResponseIsSuccessful();
        self::assertEmailCount(0);
        self::assertSame(MembershipStatus::PENDING, self::membership('unpaid')->getStatus());
    }

    public function testACompletedButUnpaidOrderSessionPromotesNothing(): void
    {
        self::setWebhookSecret();

        $client = static::createClient();
        $order = $this->seedPendingOrder('unpaidorder');
        $shakerBefore = $this->rememberProductStock('shaker');

        self::post($client, self::sessionEvent(
            'checkout.session.completed',
            'unpaidorder',
            paymentStatus: 'unpaid',
            metadata: ['order_id' => (string) $order->getId()],
        ));

        self::assertResponseIsSuccessful();
        self::assertEmailCount(0);
        self::assertSame(OrderStatus::PENDING, self::order('unpaidorder')->getStatus());
        self::assertNull(self::order('unpaidorder')->getPaidAt());
        self::assertSame($shakerBefore, self::productStock('shaker'));
    }

    public function testAPaidMembershipSessionIsActivatedAndConfirmedByMail(): void
    {
        self::setWebhookSecret();

        $client = static::createClient();
        $this->seedPendingMembership('activate');

        self::post($client, self::sessionEvent('checkout.session.completed', 'activate'));

        self::assertResponseIsSuccessful();

        // Collected before anything else touches the kernel: the mailer's
        // event collector is reset by the next request.
        self::assertEmailCount(1);

        $mail = self::getMailerMessage();
        self::assertNotNull($mail);
        self::assertEmailHeaderSame($mail, 'To', 'Marina Sokolova <prospect@speks.lv>');

        // The webhook arrives from Stripe with no locale, so the language is
        // the one on the member's profile - Russian, here.
        self::assertEmailHeaderSame($mail, 'Subject', 'Твой абонемент SPĒKS активен');

        $membership = self::membership('activate');

        self::assertSame(MembershipStatus::ACTIVE, $membership->getStatus());
        self::assertNotNull($membership->getStartsAt());
        self::assertNotNull($membership->getEndsAt());
        self::assertTrue($membership->isCurrent());
        self::assertSame('pi_'.self::SESSION_PREFIX.'activate', $membership->getStripePaymentIntentId());
    }

    /**
     * Card payments settle before the redirect; the delayed methods arrive as
     * a second event type, which has to promote just the same.
     */
    public function testAnAsyncPaymentSuccessAlsoActivatesAMembership(): void
    {
        self::setWebhookSecret();

        $client = static::createClient();
        $this->seedPendingMembership('async');

        self::post($client, self::sessionEvent('checkout.session.async_payment_succeeded', 'async'));

        self::assertResponseIsSuccessful();
        self::assertEmailCount(1);
        self::assertSame(MembershipStatus::ACTIVE, self::membership('async')->getStatus());
    }

    /**
     * A session Stripe expanded rather than referenced by id leaves
     * payment_intent as something other than a string. That is not a thing to
     * write into the column, and it must not stop the membership activating.
     */
    public function testAMembershipActivatesEvenWithNoPaymentIntentId(): void
    {
        self::setWebhookSecret();

        $client = static::createClient();
        $this->seedPendingMembership('nopi');

        self::post($client, self::sessionEvent('checkout.session.completed', 'nopi', paymentIntent: null));

        self::assertResponseIsSuccessful();

        $membership = self::membership('nopi');
        self::assertSame(MembershipStatus::ACTIVE, $membership->getStatus());
        self::assertNull($membership->getStripePaymentIntentId());
    }

    /**
     * The property that matters most. Stripe retries until it gets a 2xx, so
     * the same event arrives more than once as a matter of course - and a
     * membership extended, or a mail sent again, on the second delivery is
     * something the member notices.
     */
    public function testReplayingAMembershipEventExtendsNothingAndResendsNothing(): void
    {
        self::setWebhookSecret();

        $client = static::createClient();
        $this->seedPendingMembership('replay');

        $payload = self::sessionEvent('checkout.session.completed', 'replay');

        self::post($client, $payload);
        self::assertResponseIsSuccessful();
        self::assertEmailCount(1);

        $first = self::membership('replay');
        $startsAt = $first->getStartsAt();
        $endsAt = $first->getEndsAt();
        self::assertNotNull($startsAt);
        self::assertNotNull($endsAt);

        self::post($client, $payload);

        self::assertResponseIsSuccessful();

        // The collector is per request, so this is the replay's own count.
        self::assertEmailCount(0);

        $second = self::membership('replay');
        self::assertSame(MembershipStatus::ACTIVE, $second->getStatus());
        self::assertEquals($startsAt, $second->getStartsAt());
        self::assertEquals($endsAt, $second->getEndsAt());
    }

    public function testAPaidOrderSessionIsMarkedPaidDrawsStockDownAndSendsAReceipt(): void
    {
        self::setWebhookSecret();

        $client = static::createClient();
        $order = $this->seedPendingOrder('paidorder');

        $shakerBefore = $this->rememberProductStock('shaker');
        $variantBefore = $this->rememberVariantStock('SPK-HOD-GRY-L');

        self::post($client, self::sessionEvent(
            'checkout.session.completed',
            'paidorder',
            metadata: ['order_id' => (string) $order->getId()],
        ));

        self::assertResponseIsSuccessful();
        self::assertEmailCount(1);

        // A receipt, not a membership confirmation: two different things
        // happened to the same member and the mail has to say which.
        $mail = self::getMailerMessage();
        self::assertNotNull($mail);
        self::assertEmailHeaderSame($mail, 'Subject', 'Ваш заказ SPĒKS подтверждён');

        $paid = self::order('paidorder');

        self::assertSame(OrderStatus::PAID, $paid->getStatus());
        self::assertNotNull($paid->getPaidAt());
        self::assertSame('pi_'.self::SESSION_PREFIX.'paidorder', $paid->getStripePaymentIntentId());

        // Two shakers off the product's own stock, one hoodie off the size's.
        self::assertSame($shakerBefore - 2, self::productStock('shaker'));
        self::assertSame($variantBefore - 1, self::variantStock('SPK-HOD-GRY-L'));
    }

    /**
     * The same retry problem, with more to lose: a second delivery must not
     * draw the stock down twice or post a second receipt.
     */
    public function testReplayingAnOrderEventDrawsNoFurtherStockAndResendsNothing(): void
    {
        self::setWebhookSecret();

        $client = static::createClient();
        $order = $this->seedPendingOrder('replayorder');

        $shakerBefore = $this->rememberProductStock('shaker');
        $variantBefore = $this->rememberVariantStock('SPK-HOD-GRY-L');

        $payload = self::sessionEvent(
            'checkout.session.completed',
            'replayorder',
            metadata: ['order_id' => (string) $order->getId()],
        );

        self::post($client, $payload);
        self::assertResponseIsSuccessful();
        self::assertEmailCount(1);

        $paidAt = self::order('replayorder')->getPaidAt();
        self::assertNotNull($paidAt);

        self::post($client, $payload);

        self::assertResponseIsSuccessful();
        self::assertEmailCount(0);

        $replayed = self::order('replayorder');
        self::assertSame(OrderStatus::PAID, $replayed->getStatus());
        self::assertEquals($paidAt, $replayed->getPaidAt());

        self::assertSame($shakerBefore - 2, self::productStock('shaker'));
        self::assertSame($variantBefore - 1, self::variantStock('SPK-HOD-GRY-L'));
    }

    /**
     * Two people can pay for the last one within the same second. By the time
     * this runs Stripe has the money, so overselling a unit and sorting it out
     * at the counter is the honest failure - a negative stock column is not.
     */
    public function testStockNeverGoesBelowZero(): void
    {
        self::setWebhookSecret();

        $client = static::createClient();
        $order = $this->seedPendingOrder('oversell', shakers: 999, hoodies: 999);

        $this->rememberProductStock('shaker');
        $this->rememberVariantStock('SPK-HOD-GRY-L');

        self::post($client, self::sessionEvent(
            'checkout.session.completed',
            'oversell',
            metadata: ['order_id' => (string) $order->getId()],
        ));

        self::assertResponseIsSuccessful();
        self::assertSame(0, self::productStock('shaker'));
        self::assertSame(0, self::variantStock('SPK-HOD-GRY-L'));
    }

    public function testAnExpiredMembershipSessionExpiresThePendingRow(): void
    {
        self::setWebhookSecret();

        $client = static::createClient();
        $this->seedPendingMembership('expire');

        self::post($client, self::sessionEvent('checkout.session.expired', 'expire', paymentStatus: 'unpaid'));

        self::assertResponseIsSuccessful();
        self::assertEmailCount(0);
        self::assertSame(MembershipStatus::EXPIRED, self::membership('expire')->getStatus());
    }

    public function testAnExpiredOrderSessionExpiresThePendingOrder(): void
    {
        self::setWebhookSecret();

        $client = static::createClient();
        $order = $this->seedPendingOrder('expireorder');

        $shakerBefore = $this->rememberProductStock('shaker');

        self::post($client, self::sessionEvent(
            'checkout.session.expired',
            'expireorder',
            paymentStatus: 'unpaid',
            metadata: ['order_id' => (string) $order->getId()],
        ));

        self::assertResponseIsSuccessful();
        self::assertEmailCount(0);

        $expired = self::order('expireorder');
        self::assertSame(OrderStatus::EXPIRED, $expired->getStatus());
        self::assertNull($expired->getPaidAt());

        // An order that expired was never paid for, so nothing left the shelf.
        self::assertSame($shakerBefore, self::productStock('shaker'));
    }

    /**
     * An expiry that arrives after the payment must not undo it.
     */
    public function testAnExpiryForAnAlreadyPaidOrderChangesNothing(): void
    {
        self::setWebhookSecret();

        $client = static::createClient();
        $order = $this->seedPendingOrder('lateexpiry');

        $this->rememberProductStock('shaker');
        $this->rememberVariantStock('SPK-HOD-GRY-L');

        $orderId = (string) $order->getId();

        self::post($client, self::sessionEvent('checkout.session.completed', 'lateexpiry', metadata: ['order_id' => $orderId]));
        self::assertResponseIsSuccessful();

        self::post($client, self::sessionEvent('checkout.session.expired', 'lateexpiry', metadata: ['order_id' => $orderId]));

        self::assertResponseIsSuccessful();
        self::assertSame(OrderStatus::PAID, self::order('lateexpiry')->getStatus());
    }

    /**
     * 200, not 404: retrying will not conjure the row, and an endpoint that
     * keeps failing eventually gets disabled in the Stripe dashboard.
     */
    public function testAnEventForAnUnknownMembershipSessionIsAcceptedAndIgnored(): void
    {
        self::setWebhookSecret();

        $client = static::createClient();
        self::post($client, self::sessionEvent('checkout.session.completed', 'nosuchsession'));

        self::assertResponseIsSuccessful();
        self::assertEmailCount(0);
    }

    public function testAnEventForAnUnknownOrderSessionIsAcceptedAndIgnored(): void
    {
        self::setWebhookSecret();

        $client = static::createClient();
        self::post($client, self::sessionEvent(
            'checkout.session.completed',
            'nosuchorder',
            metadata: ['order_id' => '99999999'],
        ));

        self::assertResponseIsSuccessful();
        self::assertEmailCount(0);
    }

    public function testAnExpiryForAnUnknownSessionIsAcceptedAndIgnored(): void
    {
        self::setWebhookSecret();

        $client = static::createClient();
        self::post($client, self::sessionEvent('checkout.session.expired', 'nosuchexpiry', paymentStatus: 'unpaid'));

        self::assertResponseIsSuccessful();
    }

    /**
     * Stripe types metadata loosely, so anything that is not a positive
     * integer has to fall through to the membership path rather than be
     * handed to the order repository.
     *
     * @return iterable<string, array{string|bool|null}>
     */
    public static function unusableOrderIdProvider(): iterable
    {
        yield 'absent' => [null];
        yield 'empty' => [''];
        yield 'not a number' => ['not-an-id'];
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'a boolean' => [true];
    }

    #[DataProvider('unusableOrderIdProvider')]
    public function testAnUnusableOrderIdIsTreatedAsAMembership(string|bool|null $orderId): void
    {
        self::setWebhookSecret();

        $client = static::createClient();
        $this->seedPendingMembership('metadata');

        self::post($client, self::sessionEvent(
            'checkout.session.completed',
            'metadata',
            metadata: null === $orderId ? [] : ['order_id' => $orderId],
        ));

        self::assertResponseIsSuccessful();

        // It went down the membership path, which is the older and safer one.
        self::assertSame(MembershipStatus::ACTIVE, self::membership('metadata')->getStatus());
    }

    /**
     * Stripe sends metadata on every session, but the controller does not take
     * that on trust.
     */
    public function testASessionWithNoMetadataAtAllIsTreatedAsAMembership(): void
    {
        self::setWebhookSecret();

        $client = static::createClient();
        $this->seedPendingMembership('nometadata');

        self::post($client, self::sessionEvent('checkout.session.completed', 'nometadata', metadata: null));

        self::assertResponseIsSuccessful();
        self::assertSame(MembershipStatus::ACTIVE, self::membership('nometadata')->getStatus());
    }

    /**
     * Most of what a Stripe account emits is not a checkout session at all.
     */
    public function testAnEventWhoseObjectIsNotASessionIsIgnored(): void
    {
        self::setWebhookSecret();

        $client = static::createClient();
        self::post($client, '{"id":"evt_pi","object":"event","type":"payment_intent.succeeded","data":{"object":{"id":"pi_test_hook","object":"payment_intent","amount":4900,"status":"succeeded"}}}');

        self::assertResponseIsSuccessful();
        self::assertEmailCount(0);
    }

    /**
     * A session event this endpoint has no opinion about is acknowledged
     * rather than argued with.
     */
    public function testASessionEventOfAnUnhandledTypeIsIgnored(): void
    {
        self::setWebhookSecret();

        $client = static::createClient();
        $this->seedPendingMembership('unhandled');

        self::post($client, self::sessionEvent('checkout.session.async_payment_failed', 'unhandled', paymentStatus: 'unpaid'));

        self::assertResponseIsSuccessful();
        self::assertSame(MembershipStatus::PENDING, self::membership('unhandled')->getStatus());
    }

    private static function post(KernelBrowser $client, string $payload): void
    {
        $client->request('POST', '/webhook/stripe', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => self::sign($payload),
        ], content: $payload);

        // Everything read after this comes back off the database rather than
        // out of the identity map the request left behind. What the endpoint
        // actually wrote is the question, and a datetime column keeps whole
        // seconds where the object in memory keeps microseconds.
        self::entityManager()->clear();
    }

    /**
     * The header Stripe puts on every webhook: the timestamp it signed at, and
     * an HMAC-SHA256 of "<timestamp>.<payload>" keyed with the signing secret.
     */
    private static function sign(string $payload): string
    {
        $timestamp = time();

        return sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp.'.'.$payload, self::SIGNING_SECRET));
    }

    /**
     * A checkout.session event body, shaped the way Stripe posts one.
     *
     * $paymentIntent defaults to false rather than null so that "send no
     * payment intent" stays sayable.
     *
     * @param array<string, string|bool>|null $metadata
     */
    private static function sessionEvent(
        string $type,
        string $name,
        string $paymentStatus = 'paid',
        ?array $metadata = [],
        string|false|null $paymentIntent = false,
    ): string {
        $sessionId = self::SESSION_PREFIX.$name;

        return json_encode([
            'id' => 'evt_'.$sessionId,
            'object' => 'event',
            'type' => $type,
            'data' => [
                'object' => [
                    'id' => $sessionId,
                    'object' => 'checkout.session',
                    'payment_status' => $paymentStatus,
                    'payment_intent' => false === $paymentIntent ? 'pi_'.$sessionId : $paymentIntent,
                    'metadata' => $metadata,
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * A membership handed to Stripe and not yet paid for.
     */
    private function seedPendingMembership(string $name): UserMembership
    {
        $plans = static::getContainer()->get(MembershipPlanRepository::class);
        self::assertInstanceOf(MembershipPlanRepository::class, $plans);

        $plan = $plans->findOneActiveBySlug('all-branches');
        self::assertNotNull($plan);

        $membership = new UserMembership(self::user('prospect@speks.lv'), $plan);
        $membership->setStripeCheckoutSessionId(self::SESSION_PREFIX.$name);

        $entityManager = self::entityManager();
        $entityManager->persist($membership);
        $entityManager->flush();

        return $membership;
    }

    /**
     * An order handed to Stripe and not yet paid for: one line drawn from a
     * product's own stock and one from a variant's, so both halves of the
     * draw-down are exercised.
     */
    private function seedPendingOrder(string $name, int $shakers = 2, int $hoodies = 1): Order
    {
        $order = new Order(self::user('prospect@speks.lv'));
        $order->setStripeCheckoutSessionId(self::SESSION_PREFIX.$name);

        $shaker = self::product('shaker');
        (new OrderItem($order))
            ->setProduct($shaker)
            ->setNameSnapshot($shaker->getName())
            ->setSkuSnapshot($shaker->getSku())
            ->setUnitPriceCents($shaker->getPriceCents())
            ->setQuantity($shakers);

        $variant = self::variant('SPK-HOD-GRY-L');
        (new OrderItem($order))
            ->setProduct($variant->getProduct())
            ->setVariant($variant)
            ->setNameSnapshot(TranslatedString::of('SPĒKS hoodie · L'))
            ->setSkuSnapshot($variant->getSku())
            ->setUnitPriceCents($variant->getPriceCents())
            ->setQuantity($hoodies);

        $order->recalculateTotal();

        $entityManager = self::entityManager();
        $entityManager->persist($order);
        $entityManager->flush();

        return $order;
    }

    private static function membership(string $name): UserMembership
    {
        $repository = static::getContainer()->get(UserMembershipRepository::class);
        self::assertInstanceOf(UserMembershipRepository::class, $repository);

        $membership = $repository->findOneByCheckoutSession(self::SESSION_PREFIX.$name);
        self::assertInstanceOf(UserMembership::class, $membership);

        return $membership;
    }

    private static function order(string $name): Order
    {
        $repository = static::getContainer()->get(OrderRepository::class);
        self::assertInstanceOf(OrderRepository::class, $repository);

        $order = $repository->findOneByCheckoutSession(self::SESSION_PREFIX.$name);
        self::assertInstanceOf(Order::class, $order);

        return $order;
    }

    private static function user(string $email): User
    {
        $repository = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);

        $user = $repository->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private static function product(string $slug): Product
    {
        $repository = static::getContainer()->get(ProductRepository::class);
        self::assertInstanceOf(ProductRepository::class, $repository);

        $product = $repository->findOneBy(['slug' => $slug]);
        self::assertInstanceOf(Product::class, $product);

        return $product;
    }

    private static function variant(string $sku): ProductVariant
    {
        $variant = self::entityManager()->getRepository(ProductVariant::class)->findOneBy(['sku' => $sku]);
        self::assertInstanceOf(ProductVariant::class, $variant);

        return $variant;
    }

    private static function productStock(string $slug): int
    {
        return self::product($slug)->getStock();
    }

    private static function variantStock(string $sku): int
    {
        return self::variant($sku)->getStock();
    }

    /**
     * Reads a stock level and notes it down, so tearDown can put the fixtures
     * back however the test leaves them.
     */
    private function rememberProductStock(string $slug): int
    {
        $stock = self::productStock($slug);
        $this->stockToRestore['product:'.$slug] = $stock;

        return $stock;
    }

    private function rememberVariantStock(string $sku): int
    {
        $stock = self::variantStock($sku);
        $this->stockToRestore['variant:'.$sku] = $stock;

        return $stock;
    }

    private static function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    /**
     * The suite has no transactional rollback, so everything this file wrote
     * has to be unwritten: the rows by their session-id prefix, and the two
     * stock columns any draw-down touched.
     */
    private function restoreFixtures(): void
    {
        $entityManager = self::entityManager();

        foreach ($this->stockToRestore as $key => $stock) {
            [$kind, $identifier] = explode(':', $key, 2);

            if ('product' === $kind) {
                self::product($identifier)->setStock($stock);

                continue;
            }

            self::variant($identifier)->setStock($stock);
        }

        $entityManager->flush();
        $this->stockToRestore = [];

        $entityManager->createQuery('DELETE FROM App\Entity\UserMembership m WHERE m.stripeCheckoutSessionId LIKE :prefix')
            ->setParameter('prefix', self::SESSION_PREFIX.'%')
            ->execute();

        // The lines go with it: customer_order_item is ON DELETE CASCADE.
        $entityManager->createQuery('DELETE FROM App\Entity\Order o WHERE o.stripeCheckoutSessionId LIKE :prefix')
            ->setParameter('prefix', self::SESSION_PREFIX.'%')
            ->execute();

        $entityManager->clear();
    }

    private static function setWebhookSecret(): void
    {
        self::ensureKernelShutdown();
        $_ENV['STRIPE_WEBHOOK_SECRET'] = $_SERVER['STRIPE_WEBHOOK_SECRET'] = self::SIGNING_SECRET;
    }

    private static function clearWebhookSecret(): void
    {
        self::ensureKernelShutdown();
        $_ENV['STRIPE_WEBHOOK_SECRET'] = $_SERVER['STRIPE_WEBHOOK_SECRET'] = '';
    }
}
