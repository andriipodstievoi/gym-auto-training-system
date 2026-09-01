<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Domain\Enum\OrderStatus;
use App\Domain\TranslatedString;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The order total and the reference, with no database in sight.
 */
final class OrderTest extends TestCase
{
    public function testANewOrderIsPendingAndCostsNothing(): void
    {
        $order = new Order(self::user());

        self::assertSame(OrderStatus::PENDING, $order->getStatus());
        self::assertSame(0, $order->getTotalCents());
        self::assertNull($order->getPaidAt());
        self::assertCount(0, $order->getItems());
    }

    public function testTheReferenceIsShortReadableAndUnique(): void
    {
        $references = [];

        for ($i = 0; $i < 200; ++$i) {
            $reference = (new Order(self::user()))->getReference();

            self::assertMatchesRegularExpression('/^SPK-[2-9A-HJ-NP-Z]{8}$/', $reference);
            self::assertLessThanOrEqual(16, strlen($reference));

            $references[] = $reference;
        }

        self::assertCount(200, array_unique($references));
    }

    public function testTheEmailIsCopiedFromTheAccount(): void
    {
        $user = self::user();
        $order = new Order($user);

        $user->setEmail('moved@example.com');

        self::assertSame('jana@example.com', $order->getEmail());
    }

    public function testTheOrderRegistersItselfOnTheUser(): void
    {
        $user = self::user();
        $order = new Order($user);

        self::assertTrue($user->getOrders()->contains($order));
    }

    public function testTheTotalIsComputedFromTheLinesAndNotTakenFromInput(): void
    {
        $order = new Order(self::user());

        self::item($order, 1250, 2);
        self::item($order, 900, 3);

        $order->recalculateTotal();

        self::assertSame(2500 + 2700, $order->getTotalCents());
        self::assertSame('52.00', $order->getTotalAmount());
        self::assertSame(5, $order->getItemCount());
    }

    public function testRemovingALineRetotalsTheOrder(): void
    {
        $order = new Order(self::user());

        $keep = self::item($order, 1000, 1);
        $drop = self::item($order, 500, 4);

        $order->recalculateTotal();
        self::assertSame(3000, $order->getTotalCents());

        $order->removeItem($drop);

        self::assertSame(1000, $order->getTotalCents());
        self::assertTrue($order->getItems()->contains($keep));
    }

    public function testMarkingPaidRecordsWhen(): void
    {
        $order = new Order(self::user());
        $order->markPaid(new DateTimeImmutable('2026-03-10 12:00:00'));

        self::assertSame(OrderStatus::PAID, $order->getStatus());
        self::assertSame('2026-03-10', $order->getPaidAt()?->format('Y-m-d'));
        self::assertTrue($order->getStatus()->isSettled());
    }

    public function testOnlySettledStatusesCountAsPaidFor(): void
    {
        self::assertTrue(OrderStatus::PAID->isSettled());
        self::assertTrue(OrderStatus::FULFILLED->isSettled());
        self::assertFalse(OrderStatus::PENDING->isSettled());
        self::assertFalse(OrderStatus::CANCELLED->isSettled());
        self::assertFalse(OrderStatus::EXPIRED->isSettled());
    }

    /**
     * A line is a snapshot: deleting the product it came from must not touch
     * what somebody was charged.
     */
    public function testALineKeepsItsSnapshotWhenTheProductChanges(): void
    {
        $product = (new Product())->setSku('SPK-SHK-700')->setPriceCents(900);
        $product->setName(TranslatedString::of('Shaker'));

        $order = new Order(self::user());
        $item = (new OrderItem($order))
            ->setProduct($product)
            ->setNameSnapshot($product->getName())
            ->setSkuSnapshot($product->getSku())
            ->setUnitPriceCents($product->getPriceCents())
            ->setQuantity(2);

        $product->setPriceCents(9900)->setName(TranslatedString::of('Renamed'));

        self::assertSame(900, $item->getUnitPriceCents());
        self::assertSame('Shaker', $item->getNameSnapshot()->get('en'));
        self::assertSame('SPK-SHK-700', $item->getSkuSnapshot());
        self::assertSame(1800, $item->getLineTotalCents());
        self::assertSame('18.00', $item->getLineTotalAmount());
    }

    private static function item(Order $order, int $unitPriceCents, int $quantity): OrderItem
    {
        return (new OrderItem($order))
            ->setNameSnapshot(TranslatedString::of('Something'))
            ->setSkuSnapshot('SPK-TEST')
            ->setUnitPriceCents($unitPriceCents)
            ->setQuantity($quantity);
    }

    private static function user(): User
    {
        return (new User())
            ->setEmail('jana@example.com')
            ->setFirstName('Jana')
            ->setLastName('Ozola');
    }
}
