<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Domain\TranslatedString;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Two orders for the seeded member, so the account page and the back office
 * have something real to render without anybody touching Stripe.
 *
 * One paid and one still pending, because both are states the site has to show
 * honestly: pending is what an abandoned Stripe page leaves behind.
 */
final class OrderFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $member = $this->getReference(UserFixtures::REFERENCE_MEMBER, User::class);

        $paid = new Order($member);
        $paid->setStripeCheckoutSessionId('cs_test_fixture_order_paid')
            ->setStripePaymentIntentId('pi_test_fixture_order_paid')
            ->markPaid(new DateTimeImmutable('-11 days'));

        $this->line($paid, 'training-hoodie', 'SPK-HOD-GRY-L', 1);
        $this->line($paid, 'shaker', null, 2);
        $paid->recalculateTotal();

        $pending = new Order($member);
        $this->line($pending, 'creatine-monohydrate', null, 1);
        $pending->recalculateTotal();

        $manager->persist($paid);
        $manager->persist($pending);
        $manager->flush();
    }

    /**
     * @return list<class-string>
     */
    public function getDependencies(): array
    {
        return [UserFixtures::class, ShopFixtures::class];
    }

    /**
     * Snapshots a catalogue row onto an order line, exactly as checkout does.
     */
    private function line(Order $order, string $productSlug, ?string $variantSku, int $quantity): void
    {
        $product = $this->getReference(ShopFixtures::REFERENCE_PREFIX.$productSlug, Product::class);
        $variant = null;

        foreach ($product->getVariants() as $candidate) {
            if ($candidate->getSku() === $variantSku) {
                $variant = $candidate;

                break;
            }
        }

        $name = $product->getName();
        $sku = $product->getSku();
        $priceCents = $product->getPriceCents();

        if ($variant instanceof ProductVariant) {
            $values = [];

            foreach (TranslatedString::LOCALES as $locale) {
                $values[$locale] = $product->getName()->get($locale).' · '.$variant->getLabel()->get($locale);
            }

            $name = new TranslatedString($values);
            $sku = $variant->getSku();
            $priceCents = $variant->getPriceCents();
        }

        (new OrderItem($order))
            ->setProduct($product)
            ->setVariant($variant)
            ->setNameSnapshot($name)
            ->setSkuSnapshot($sku)
            ->setUnitPriceCents($priceCents)
            ->setQuantity($quantity);
    }
}
