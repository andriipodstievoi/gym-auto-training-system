<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Domain\TranslatedString;
use App\Entity\MembershipPlan;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\User;
use App\Entity\UserMembership;
use App\Payment\PaymentsNotConfigured;
use App\Payment\StripeCheckout;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Stripe\ApiRequestor;
use Stripe\Checkout\Session;
use Stripe\Exception\SignatureVerificationException;
use Stripe\HttpClient\CurlClient;
use UnexpectedValueException;

/**
 * Everything this app knows about Stripe, checked without a Stripe account.
 *
 * No kernel and no network. The keys below are fabricated strings that exist
 * only inside this file - the repository ships with empty ones and that stays
 * true. What a real key would unlock is proved instead by signing payloads
 * locally with the same HMAC Stripe uses, and by handing the SDK a transport
 * that answers from an array rather than from api.stripe.com.
 */
final class StripeCheckoutTest extends TestCase
{
    /**
     * Fabricated. Never sent anywhere - the transport is stubbed out in the
     * two tests that get as far as constructing a client.
     */
    private const string SECRET_KEY = 'sk_test_fake';

    private const string SIGNING_SECRET = 'whsec_fake_secret';

    /**
     * A minimal but well-formed event, as Stripe would post it.
     */
    private const string EVENT_PAYLOAD = '{"id":"evt_test_signed","object":"event","type":"checkout.session.completed","data":{"object":{"id":"cs_test_signed","object":"checkout.session","payment_status":"paid"}}}';

    protected function tearDown(): void
    {
        // The SDK's transport is a static, so put the real one back rather
        // than leaving a stub behind for whatever runs next.
        ApiRequestor::setHttpClient(CurlClient::instance());

        parent::tearDown();
    }

    public function testAnInstallWithNoKeysSaysSoRatherThanPretending(): void
    {
        $stripe = new StripeCheckout('', '');

        self::assertFalse($stripe->isConfigured());
        self::assertFalse($stripe->canVerifyWebhooks());
    }

    public function testAnInstallWithBothKeysIsFullyConfigured(): void
    {
        $stripe = new StripeCheckout(self::SECRET_KEY, self::SIGNING_SECRET);

        self::assertTrue($stripe->isConfigured());
        self::assertTrue($stripe->canVerifyWebhooks());
    }

    /**
     * The two keys are independent: a secret key does not imply a signing
     * secret, and an install that has only one half must not claim the other.
     */
    public function testTheTwoKeysAreAnsweredSeparately(): void
    {
        $checkoutOnly = new StripeCheckout(self::SECRET_KEY, '');

        self::assertTrue($checkoutOnly->isConfigured());
        self::assertFalse($checkoutOnly->canVerifyWebhooks());

        $webhooksOnly = new StripeCheckout('', self::SIGNING_SECRET);

        self::assertFalse($webhooksOnly->isConfigured());
        self::assertTrue($webhooksOnly->canVerifyWebhooks());
    }

    /**
     * The one that keeps a keyless install off the network. A membership that
     * is ready to be charged for still must not reach Stripe.
     */
    public function testCreatingAMembershipSessionWithNoKeyRefusesBeforeTheNetwork(): void
    {
        $stripe = new StripeCheckout('', '');

        $this->expectException(PaymentsNotConfigured::class);
        $this->expectExceptionMessage('STRIPE_SECRET_KEY is empty');

        $stripe->createSession(self::membership(41), 'https://speks.test/ok', 'https://speks.test/no', 'en');
    }

    public function testCreatingAnOrderSessionWithNoKeyRefusesBeforeTheNetwork(): void
    {
        $stripe = new StripeCheckout('', '');

        $this->expectException(PaymentsNotConfigured::class);
        $this->expectExceptionMessage('STRIPE_SECRET_KEY is empty');

        $stripe->createOrderSession(self::order(42), 'https://speks.test/ok', 'https://speks.test/no', 'en');
    }

    /**
     * The webhook finds the row by the id in the metadata, so a membership
     * that was never persisted has nothing to come back to.
     */
    public function testAMembershipWithNoIdIsRefused(): void
    {
        $stripe = new StripeCheckout(self::SECRET_KEY, self::SIGNING_SECRET);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Persist the membership before sending it to Stripe');

        $stripe->createSession(self::membership(null), 'https://speks.test/ok', 'https://speks.test/no', 'en');
    }

    public function testAnOrderWithNoIdIsRefused(): void
    {
        $stripe = new StripeCheckout(self::SECRET_KEY, self::SIGNING_SECRET);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Persist the order before sending it to Stripe');

        $stripe->createOrderSession(self::order(null), 'https://speks.test/ok', 'https://speks.test/no', 'en');
    }

    /**
     * The id check runs before the key check, so an unpersisted row is refused
     * even on an install that has no keys at all.
     */
    public function testTheIdIsCheckedEvenWithNoKeys(): void
    {
        $stripe = new StripeCheckout('', '');

        $this->expectException(LogicException::class);

        $stripe->createSession(self::membership(null), 'https://speks.test/ok', 'https://speks.test/no', 'en');
    }

    /**
     * What actually gets handed to Stripe, read off a stubbed transport.
     *
     * The membership id has to travel in metadata or the webhook cannot find
     * the row again, and the amount has to be the one the membership wrote
     * down rather than the plan's current price.
     */
    public function testAMembershipSessionCarriesTheIdAndTheSnapshottedPrice(): void
    {
        $transport = self::stubTransport();
        ApiRequestor::setHttpClient($transport);

        $stripe = new StripeCheckout(self::SECRET_KEY, self::SIGNING_SECRET);
        $session = $stripe->createSession(
            self::membership(41),
            'https://speks.test/ok?session_id={CHECKOUT_SESSION_ID}',
            'https://speks.test/no',
            'lv',
        );

        self::assertSame('cs_test_stub', $session->id);

        $sent = $transport->lastParams;

        self::assertSame('payment', $sent['mode'] ?? null);
        self::assertSame('lv', $sent['locale'] ?? null);
        self::assertSame('41', $sent['client_reference_id'] ?? null);
        self::assertSame(['membership_id' => '41'], $sent['metadata'] ?? null);
        self::assertSame('prospect@speks.lv', $sent['customer_email'] ?? null);

        // The literal placeholder survives: Stripe substitutes it itself.
        self::assertSame('https://speks.test/ok?session_id={CHECKOUT_SESSION_ID}', $sent['success_url'] ?? null);

        $lineItems = $sent['line_items'] ?? null;
        self::assertIsArray($lineItems);
        self::assertCount(1, $lineItems);

        $line = $lineItems[0];
        self::assertIsArray($line);
        self::assertSame(1, $line['quantity'] ?? null);

        $priceData = $line['price_data'] ?? null;
        self::assertIsArray($priceData);
        self::assertSame('eur', $priceData['currency'] ?? null);
        self::assertSame(4900, $priceData['unit_amount'] ?? null);
        self::assertSame(
            ['name' => 'Visas filiāles', 'description' => 'Katra zāle Rīgā.'],
            $priceData['product_data'] ?? null,
        );
    }

    /**
     * Stripe takes a description or no key at all, never a null one, so an
     * empty description has to be left out rather than passed through.
     */
    public function testAPlanWithNoDescriptionSendsNoDescriptionKey(): void
    {
        $transport = self::stubTransport();
        ApiRequestor::setHttpClient($transport);

        $membership = self::membership(41);
        $membership->getPlan()->setDescription(null);

        $stripe = new StripeCheckout(self::SECRET_KEY, self::SIGNING_SECRET);
        $stripe->createSession($membership, 'https://speks.test/ok', 'https://speks.test/no', 'en');

        $lineItems = $transport->lastParams['line_items'] ?? null;
        self::assertIsArray($lineItems);
        $line = $lineItems[0];
        self::assertIsArray($line);
        $priceData = $line['price_data'] ?? null;
        self::assertIsArray($priceData);

        self::assertSame(['name' => 'All branches'], $priceData['product_data'] ?? null);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function localeProvider(): iterable
    {
        yield 'english' => ['en', 'en'];
        yield 'latvian' => ['lv', 'lv'];
        yield 'russian' => ['ru', 'ru'];
        yield 'anything else is left to Stripe' => ['de', 'auto'];
    }

    #[DataProvider('localeProvider')]
    public function testOnlyTheThreeLocalesThisSiteSpeaksArePassedThrough(string $locale, string $expected): void
    {
        $transport = self::stubTransport();
        ApiRequestor::setHttpClient($transport);

        $stripe = new StripeCheckout(self::SECRET_KEY, self::SIGNING_SECRET);
        $stripe->createSession(self::membership(41), 'https://speks.test/ok', 'https://speks.test/no', $locale);

        self::assertSame($expected, $transport->lastParams['locale'] ?? null);
    }

    /**
     * One line item per order line, priced from the order's own snapshots.
     */
    public function testAnOrderSessionSendsOneLinePerOrderLine(): void
    {
        $transport = self::stubTransport();
        ApiRequestor::setHttpClient($transport);

        $stripe = new StripeCheckout(self::SECRET_KEY, self::SIGNING_SECRET);
        $stripe->createOrderSession(self::order(42), 'https://speks.test/ok', 'https://speks.test/no', 'en');

        $sent = $transport->lastParams;

        // Prefixed, so the Stripe dashboard says which of the two things this
        // app sells a payment was for.
        self::assertSame('order_42', $sent['client_reference_id'] ?? null);
        self::assertSame(['order_id' => '42'], $sent['metadata'] ?? null);

        $lineItems = $sent['line_items'] ?? null;
        self::assertIsArray($lineItems);
        self::assertCount(2, $lineItems);

        self::assertSame(
            [
                ['quantity' => 2, 'price_data' => ['currency' => 'eur', 'unit_amount' => 1290, 'product_data' => ['name' => 'Shaker']]],
                ['quantity' => 1, 'price_data' => ['currency' => 'eur', 'unit_amount' => 2990, 'product_data' => ['name' => 'Whey protein']]],
            ],
            $lineItems,
        );
    }

    public function testVerifyingAWebhookWithNoSigningSecretRefuses(): void
    {
        $stripe = new StripeCheckout(self::SECRET_KEY, '');

        $this->expectException(PaymentsNotConfigured::class);
        $this->expectExceptionMessage('STRIPE_WEBHOOK_SECRET is empty');

        $stripe->verifyWebhook(self::EVENT_PAYLOAD, self::sign(self::EVENT_PAYLOAD, self::SIGNING_SECRET));
    }

    /**
     * The positive case the three rejections below are only meaningful against:
     * a payload signed the way Stripe signs one is accepted and parsed.
     */
    public function testAProperlySignedPayloadIsAccepted(): void
    {
        $stripe = new StripeCheckout(self::SECRET_KEY, self::SIGNING_SECRET);

        $event = $stripe->verifyWebhook(
            self::EVENT_PAYLOAD,
            self::sign(self::EVENT_PAYLOAD, self::SIGNING_SECRET),
        );

        self::assertSame('evt_test_signed', $event->id);
        self::assertSame('checkout.session.completed', $event->type);

        $object = $event->data->object;
        self::assertInstanceOf(Session::class, $object);
        self::assertSame('cs_test_signed', $object->id);
        self::assertSame('paid', $object->payment_status);
    }

    /**
     * Signing one payload and posting another is the attack the header exists
     * to stop.
     */
    public function testATamperedPayloadIsRejected(): void
    {
        $stripe = new StripeCheckout(self::SECRET_KEY, self::SIGNING_SECRET);
        $header = self::sign(self::EVENT_PAYLOAD, self::SIGNING_SECRET);

        $tampered = str_replace('cs_test_signed', 'cs_test_someone_elses', self::EVENT_PAYLOAD);
        self::assertNotSame(self::EVENT_PAYLOAD, $tampered);

        $this->expectException(SignatureVerificationException::class);

        $stripe->verifyWebhook($tampered, $header);
    }

    public function testASignatureFromTheWrongSecretIsRejected(): void
    {
        $stripe = new StripeCheckout(self::SECRET_KEY, self::SIGNING_SECRET);

        $this->expectException(SignatureVerificationException::class);

        $stripe->verifyWebhook(self::EVENT_PAYLOAD, self::sign(self::EVENT_PAYLOAD, 'whsec_someone_elses_secret'));
    }

    /**
     * A correctly signed payload still expires. Without the tolerance check a
     * captured POST could be replayed for as long as the secret lived.
     */
    public function testACorrectlySignedButStaleSignatureIsRejected(): void
    {
        $stripe = new StripeCheckout(self::SECRET_KEY, self::SIGNING_SECRET);

        // Well outside the SDK's five-minute default tolerance.
        $header = self::sign(self::EVENT_PAYLOAD, self::SIGNING_SECRET, time() - 3600);

        $this->expectException(SignatureVerificationException::class);
        $this->expectExceptionMessage('Timestamp outside the tolerance zone');

        $stripe->verifyWebhook(self::EVENT_PAYLOAD, $header);
    }

    public function testAHeaderWithNoTimestampIsRejected(): void
    {
        $stripe = new StripeCheckout(self::SECRET_KEY, self::SIGNING_SECRET);

        $this->expectException(SignatureVerificationException::class);

        $stripe->verifyWebhook(
            self::EVENT_PAYLOAD,
            'v1='.hash_hmac('sha256', '.'.self::EVENT_PAYLOAD, self::SIGNING_SECRET),
        );
    }

    public function testAnEmptyHeaderIsRejected(): void
    {
        $stripe = new StripeCheckout(self::SECRET_KEY, self::SIGNING_SECRET);

        $this->expectException(SignatureVerificationException::class);

        $stripe->verifyWebhook(self::EVENT_PAYLOAD, '');
    }

    /**
     * Signed, in date, and still not JSON. The signature says who sent it, not
     * that it means anything.
     */
    public function testABodyThatIsNotJsonIsRejectedEvenWhenSignedCorrectly(): void
    {
        $stripe = new StripeCheckout(self::SECRET_KEY, self::SIGNING_SECRET);
        $payload = 'this is not JSON at all';

        $this->expectException(UnexpectedValueException::class);

        $stripe->verifyWebhook($payload, self::sign($payload, self::SIGNING_SECRET));
    }

    /**
     * The header Stripe puts on every webhook: the timestamp it signed at, and
     * an HMAC-SHA256 of "<timestamp>.<payload>" keyed with the signing secret.
     */
    private static function sign(string $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp.'.'.$payload, $secret));
    }

    /**
     * A membership priced at 49 euro, optionally with the id a persisted row
     * would carry.
     */
    private static function membership(?int $id): UserMembership
    {
        $user = (new User())->setEmail('prospect@speks.lv');

        $plan = new MembershipPlan();
        $plan->setSlug('all-branches')
            ->setName(TranslatedString::of('All branches', 'Visas filiāles', 'Все филиалы'))
            ->setDescription(TranslatedString::of('Every room in Riga.', 'Katra zāle Rīgā.', 'Каждый зал в Риге.'))
            ->setPriceCents(4900);

        $membership = new UserMembership($user, $plan);

        if (null !== $id) {
            self::forceId($membership, $id);
        }

        return $membership;
    }

    /**
     * A two-line order, optionally with the id a persisted row would carry.
     */
    private static function order(?int $id): Order
    {
        $user = (new User())->setEmail('member@speks.lv');
        $order = new Order($user);

        (new OrderItem($order))
            ->setNameSnapshot(TranslatedString::of('Shaker', 'Šeikeris', 'Шейкер'))
            ->setSkuSnapshot('SPK-SHK')
            ->setUnitPriceCents(1290)
            ->setQuantity(2);

        (new OrderItem($order))
            ->setNameSnapshot(TranslatedString::of('Whey protein', 'Sūkalu proteīns', 'Сывороточный протеин'))
            ->setSkuSnapshot('SPK-WHEY-VAN')
            ->setUnitPriceCents(2990)
            ->setQuantity(1);

        $order->recalculateTotal();

        if (null !== $id) {
            self::forceId($order, $id);
        }

        return $order;
    }

    /**
     * Doctrine writes the id and nothing else may, so a unit test that needs a
     * persisted-looking row has to reach past the entity's own API.
     */
    private static function forceId(Order|UserMembership $entity, int $id): void
    {
        (new ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }

    /**
     * A transport that answers every call from a fixed body and remembers what
     * it was asked to send. Nothing leaves the machine.
     */
    private static function stubTransport(): StripeTransportSpy
    {
        return StripeTransportSpy::answering(
            '{"id":"cs_test_stub","object":"checkout.session","url":"https://checkout.stripe.test/c/pay/cs_test_stub","payment_status":"unpaid"}',
        );
    }
}
