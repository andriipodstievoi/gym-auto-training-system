<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Domain\Enum\BillingInterval;
use App\Domain\TranslatedString;
use App\Entity\MembershipPlan;
use PHPUnit\Framework\TestCase;

/**
 * What a tier costs and what it promises.
 *
 * The pricing page puts a monthly and a yearly plan side by side, so the two
 * numbers that matter are the monthly equivalent - the only honest way to
 * compare them - and the feature bullets, which are stored per locale and
 * resolved one locale at a time.
 */
final class MembershipPlanTest extends TestCase
{
    public function testANewPlanIsAFreeMonthlyOneGoodEverywhere(): void
    {
        $plan = new MembershipPlan();

        self::assertSame(BillingInterval::MONTHLY, $plan->getBillingInterval());
        self::assertSame(0, $plan->getPriceCents());
        self::assertSame('0.00', $plan->getPriceAmount());
        self::assertTrue($plan->isActive());
        self::assertTrue($plan->isAllBranches());
        self::assertSame([], $plan->getFeatures());
        self::assertSame([], $plan->getFeatureLines('en'));
    }

    public function testAMonthlyPlanIsItsOwnMonthlyEquivalent(): void
    {
        $plan = self::plan(3990, BillingInterval::MONTHLY);

        self::assertSame(3990, $plan->getMonthlyEquivalentCents());
        self::assertSame('39.90', $plan->getPriceAmount());
    }

    public function testAYearlyPlanIsComparedByWhatOneMonthOfItCosts(): void
    {
        $plan = self::plan(39900, BillingInterval::YEARLY);

        self::assertSame('399.00', $plan->getPriceAmount());
        self::assertSame(3325, $plan->getMonthlyEquivalentCents());
    }

    /**
     * Twelve does not divide most annual prices, and money is integer cents, so
     * the remainder is dropped rather than rounded up: the plan never looks
     * dearer per month than it actually is.
     */
    public function testTheMonthlyEquivalentNeverRoundsUpwards(): void
    {
        self::assertSame(831, self::plan(9980, BillingInterval::YEARLY)->getMonthlyEquivalentCents());
        self::assertSame(0, self::plan(11, BillingInterval::YEARLY)->getMonthlyEquivalentCents());
    }

    public function testTheCheaperMonthlyEquivalentIsTheOneTheYearlyPlanOffers(): void
    {
        $monthly = self::plan(3990, BillingInterval::MONTHLY);
        $yearly = self::plan(39900, BillingInterval::YEARLY);

        self::assertLessThan($monthly->getMonthlyEquivalentCents(), $yearly->getMonthlyEquivalentCents());
    }

    public function testFeatureBulletsAreResolvedIntoOneLocale(): void
    {
        $plan = (new MembershipPlan())->setFeatures([
            ['en' => 'All branches', 'lv' => 'Visas filiāles', 'ru' => 'Все филиалы'],
            ['en' => 'Free towel', 'lv' => 'Bezmaksas dvielis', 'ru' => 'Бесплатное полотенце'],
        ]);

        self::assertSame(['All branches', 'Free towel'], $plan->getFeatureLines('en'));
        self::assertSame(['Visas filiāles', 'Bezmaksas dvielis'], $plan->getFeatureLines('lv'));
        self::assertSame(['Все филиалы', 'Бесплатное полотенце'], $plan->getFeatureLines('ru'));
    }

    /**
     * A bullet nobody has translated yet still has to print something, and it
     * falls back exactly the way every other translated field does.
     */
    public function testAnUntranslatedBulletFallsBackRatherThanPrintingNothing(): void
    {
        $plan = (new MembershipPlan())->setFeatures([
            ['lv' => 'Visas filiāles'],
            ['en' => 'Free towel'],
        ]);

        self::assertSame(['Visas filiāles', 'Free towel'], $plan->getFeatureLines('en'));
        self::assertSame(['Visas filiāles', 'Free towel'], $plan->getFeatureLines('ru'));
    }

    public function testABulletWithNoTextAtAllComesBackEmptyRatherThanBeingDropped(): void
    {
        $plan = (new MembershipPlan())->setFeatures([['en' => 'Sauna'], [], ['en' => '   ']]);

        // The count has to survive so the template's bullet list lines up with
        // what the back office typed.
        self::assertSame(['Sauna', '', ''], $plan->getFeatureLines('en'));
    }

    public function testAPlanPrintsAsItsNameInTheDefaultLocale(): void
    {
        $plan = new MembershipPlan();
        $plan->setName(new TranslatedString(['lv' => 'Pilnais']));

        self::assertSame('Pilnais', (string) $plan);
    }

    public function testTheSettersChainAndStoreWhatTheyWereGiven(): void
    {
        $plan = new MembershipPlan();
        $name = TranslatedString::of('Full');
        $description = TranslatedString::of('Everything we have');

        $returned = $plan
            ->setSlug('full')
            ->setName($name)
            ->setDescription($description)
            ->setPriceCents(4990)
            ->setBillingInterval(BillingInterval::YEARLY)
            ->setFeatures([['en' => 'Sauna']])
            ->setAllBranches(false)
            ->setActive(false)
            ->setPosition(2);

        self::assertSame($plan, $returned);
        self::assertSame('full', $plan->getSlug());
        self::assertSame($name, $plan->getName());
        self::assertSame($description, $plan->getDescription());
        self::assertSame(4990, $plan->getPriceCents());
        self::assertSame(BillingInterval::YEARLY, $plan->getBillingInterval());
        self::assertSame([['en' => 'Sauna']], $plan->getFeatures());
        self::assertFalse($plan->isAllBranches());
        self::assertFalse($plan->isActive());
        self::assertSame(2, $plan->getPosition());
        self::assertNull($plan->getId());
    }

    private static function plan(int $priceCents, BillingInterval $interval): MembershipPlan
    {
        return (new MembershipPlan())->setPriceCents($priceCents)->setBillingInterval($interval);
    }
}
