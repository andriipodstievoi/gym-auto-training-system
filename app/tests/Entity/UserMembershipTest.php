<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Domain\Enum\BillingInterval;
use App\Domain\Enum\MembershipStatus;
use App\Entity\MembershipPlan;
use App\Entity\User;
use App\Entity\UserMembership;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The period arithmetic and the access rules, with no database in sight.
 */
final class UserMembershipTest extends TestCase
{
    public function testANewMembershipIsPendingAndOpensNothing(): void
    {
        $membership = self::membership(BillingInterval::MONTHLY);

        self::assertSame(MembershipStatus::PENDING, $membership->getStatus());
        self::assertFalse($membership->isCurrent());
        self::assertNull($membership->getStartsAt());
        self::assertNull($membership->getEndsAt());
    }

    public function testTheChargedPriceIsCopiedFromThePlan(): void
    {
        $plan = self::plan(BillingInterval::MONTHLY, 4490);
        $membership = new UserMembership(self::user(), $plan);

        // Repricing the tier afterwards must not rewrite history.
        $plan->setPriceCents(9900);

        self::assertSame(4490, $membership->getPricePaidCents());
    }

    public function testActivatingAMonthlyPlanRunsForOneMonth(): void
    {
        $membership = self::membership(BillingInterval::MONTHLY);
        $membership->activate(new DateTimeImmutable('2026-03-10 12:00:00'));

        self::assertSame(MembershipStatus::ACTIVE, $membership->getStatus());
        self::assertSame('2026-03-10', $membership->getStartsAt()?->format('Y-m-d'));
        self::assertSame('2026-04-10', $membership->getEndsAt()?->format('Y-m-d'));
    }

    public function testActivatingAYearlyPlanRunsForTwelveMonths(): void
    {
        $membership = self::membership(BillingInterval::YEARLY);
        $membership->activate(new DateTimeImmutable('2026-03-10 12:00:00'));

        self::assertSame('2027-03-10', $membership->getEndsAt()?->format('Y-m-d'));
    }

    public function testAnActiveMembershipOpensTheDoorUntilItRunsOut(): void
    {
        $membership = self::membership(BillingInterval::MONTHLY);
        $membership->activate(new DateTimeImmutable('2026-03-10'));

        self::assertTrue($membership->isCurrent(new DateTimeImmutable('2026-04-09')));
        self::assertFalse($membership->isCurrent(new DateTimeImmutable('2026-04-11')));
    }

    /**
     * Cancelling stops the renewal; it does not refund the period already paid
     * for, so access survives to the end date.
     */
    public function testCancellingKeepsAccessUntilTheEndOfThePaidPeriod(): void
    {
        $membership = self::membership(BillingInterval::MONTHLY);
        $membership->activate(new DateTimeImmutable('2026-03-10'));
        $membership->cancel();

        self::assertSame(MembershipStatus::CANCELLED, $membership->getStatus());
        self::assertTrue($membership->isCurrent(new DateTimeImmutable('2026-04-01')));
        self::assertFalse($membership->isCurrent(new DateTimeImmutable('2026-04-20')));
    }

    public function testAnExpiredMembershipOpensNothing(): void
    {
        $membership = self::membership(BillingInterval::MONTHLY);
        $membership->activate(new DateTimeImmutable('2026-03-10'));
        $membership->setStatus(MembershipStatus::EXPIRED);

        self::assertFalse($membership->isCurrent(new DateTimeImmutable('2026-03-11')));
    }

    public function testDaysRemainingCountsDownAndStopsAtZero(): void
    {
        $membership = self::membership(BillingInterval::MONTHLY);
        $membership->activate(new DateTimeImmutable('2026-03-10 00:00:00'));

        self::assertSame(31, $membership->getDaysRemaining(new DateTimeImmutable('2026-03-10 00:00:00')));
        self::assertSame(1, $membership->getDaysRemaining(new DateTimeImmutable('2026-04-09 00:00:00')));
        self::assertSame(0, $membership->getDaysRemaining(new DateTimeImmutable('2026-05-01 00:00:00')));
    }

    public function testTheMembershipRegistersItselfOnTheUser(): void
    {
        $user = self::user();
        $membership = new UserMembership($user, self::plan(BillingInterval::MONTHLY, 3490));

        self::assertTrue($user->getMemberships()->contains($membership));
    }

    private static function membership(BillingInterval $interval): UserMembership
    {
        return new UserMembership(self::user(), self::plan($interval, 3490));
    }

    private static function user(): User
    {
        return (new User())
            ->setEmail('jana@example.com')
            ->setFirstName('Jana')
            ->setLastName('Ozola');
    }

    private static function plan(BillingInterval $interval, int $priceCents): MembershipPlan
    {
        return (new MembershipPlan())
            ->setSlug('test-plan')
            ->setPriceCents($priceCents)
            ->setBillingInterval($interval);
    }
}
