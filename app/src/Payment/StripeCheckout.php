<?php

declare(strict_types=1);

namespace App\Payment;

use App\Entity\Order;
use App\Entity\UserMembership;
use LogicException;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Stripe\Webhook;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use UnexpectedValueException;

/**
 * Everything this app knows about Stripe.
 *
 * The keys are deliberately allowed to be empty. This machine has none, CI has
 * none, and the site still has to boot and render every page - so nothing here
 * touches the network or the SDK until {@see isConfigured()} says it may, and
 * callers are expected to ask first.
 */
final class StripeCheckout
{
    /**
     * Stripe Checkout's own supported locales happen to cover all three of
     * ours, so the payment page is shown in the language the member is
     * browsing in rather than guessed from their browser.
     */
    private const array SUPPORTED_LOCALES = ['en', 'lv', 'ru'];

    private ?StripeClient $client = null;

    public function __construct(
        #[Autowire('%env(STRIPE_SECRET_KEY)%')]
        private readonly string $secretKey,
        #[Autowire('%env(STRIPE_WEBHOOK_SECRET)%')]
        private readonly string $webhookSecret,
    ) {
    }

    /**
     * Whether a secret key exists at all. Without one there is no checkout,
     * and the membership page says so instead of offering a button.
     */
    public function isConfigured(): bool
    {
        return '' !== $this->secretKey;
    }

    /**
     * Whether inbound webhooks can be trusted. Verification is not optional:
     * with no signing secret the endpoint refuses everything rather than
     * taking an unauthenticated POST's word that money arrived.
     */
    public function canVerifyWebhooks(): bool
    {
        return '' !== $this->webhookSecret;
    }

    /**
     * Hands a pending membership to Stripe and returns the URL to send the
     * member to.
     *
     * The membership id travels in both client_reference_id and metadata: the
     * webhook reads metadata, and client_reference_id is what makes a payment
     * traceable from the Stripe dashboard back to a row here.
     *
     * @throws ApiErrorException          when Stripe rejects the request
     * @throws PaymentsNotConfigured      when no secret key is set
     * @throws LogicException            when the membership was never persisted
     */
    public function createSession(
        UserMembership $membership,
        string $successUrl,
        string $cancelUrl,
        string $locale,
    ): Session {
        $membershipId = $membership->getId();

        if (null === $membershipId) {
            throw new LogicException('Persist the membership before sending it to Stripe; the webhook needs its id to find it again.');
        }

        $plan = $membership->getPlan();

        // Stripe takes a description or no key at all, never a null one.
        $productData = ['name' => $plan->getName()->get($locale)];
        $description = $plan->getDescription()?->get($locale) ?? '';

        if ('' !== $description) {
            $productData['description'] = $description;
        }

        return $this->client()->checkout->sessions->create([
            'mode' => 'payment',
            'locale' => in_array($locale, self::SUPPORTED_LOCALES, true) ? $locale : 'auto',
            'customer_email' => $membership->getUser()->getEmail(),
            'client_reference_id' => (string) $membershipId,
            'metadata' => ['membership_id' => (string) $membershipId],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'eur',
                    'unit_amount' => $membership->getPricePaidCents(),
                    'product_data' => $productData,
                ],
            ]],
        ]);
    }

    /**
     * Hands a pending shop order to Stripe, one line item per order line.
     *
     * The amounts come from the order's own snapshots, never from the cart or
     * the request that built it: by the time this runs the prices have already
     * been read from the database and written down.
     *
     * client_reference_id is prefixed so a glance at the Stripe dashboard says
     * which of the two things this app sells a payment was for; the webhook
     * reads metadata.order_id.
     *
     * @throws ApiErrorException     when Stripe rejects the request
     * @throws PaymentsNotConfigured when no secret key is set
     * @throws LogicException        when the order was never persisted
     */
    public function createOrderSession(
        Order $order,
        string $successUrl,
        string $cancelUrl,
        string $locale,
    ): Session {
        $orderId = $order->getId();

        if (null === $orderId) {
            throw new LogicException('Persist the order before sending it to Stripe; the webhook needs its id to find it again.');
        }

        $lineItems = [];

        foreach ($order->getItems() as $item) {
            $lineItems[] = [
                'quantity' => $item->getQuantity(),
                'price_data' => [
                    'currency' => 'eur',
                    'unit_amount' => $item->getUnitPriceCents(),
                    'product_data' => ['name' => $item->getNameSnapshot()->get($locale)],
                ],
            ];
        }

        return $this->client()->checkout->sessions->create([
            'mode' => 'payment',
            'locale' => in_array($locale, self::SUPPORTED_LOCALES, true) ? $locale : 'auto',
            'customer_email' => $order->getEmail(),
            'client_reference_id' => 'order_'.$orderId,
            'metadata' => ['order_id' => (string) $orderId],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'line_items' => $lineItems,
        ]);
    }

    /**
     * Verifies the signature Stripe sends with every webhook and returns the
     * event it authenticates.
     *
     * @throws PaymentsNotConfigured                                when no signing secret is set
     * @throws \Stripe\Exception\SignatureVerificationException      when the signature does not match
     * @throws UnexpectedValueException                             when the body is not valid JSON
     */
    public function verifyWebhook(string $payload, string $signatureHeader): Event
    {
        if (!$this->canVerifyWebhooks()) {
            throw new PaymentsNotConfigured('STRIPE_WEBHOOK_SECRET is empty, so no webhook can be trusted.');
        }

        return Webhook::constructEvent($payload, $signatureHeader, $this->webhookSecret);
    }

    /**
     * @throws PaymentsNotConfigured
     */
    private function client(): StripeClient
    {
        if (!$this->isConfigured()) {
            throw new PaymentsNotConfigured('STRIPE_SECRET_KEY is empty, so there is nothing to charge against.');
        }

        return $this->client ??= new StripeClient($this->secretKey);
    }
}
