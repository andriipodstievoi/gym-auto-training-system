<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\MembershipPlan;
use App\Entity\User;
use App\Entity\UserMembership;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Four accounts covering the states the site has to handle: staff, a member who
 * holds an active membership, a member who holds nothing yet, and a coach.
 *
 * The coach account carries no special role - BookingFixtures points a trainer
 * row at it, and being a coach is exactly that and nothing else.
 *
 * The shared development password is fine here and nowhere else - these rows
 * only ever exist in a local or CI database.
 */
final class UserFixtures extends Fixture implements DependentFixtureInterface
{
    public const string REFERENCE_ADMIN = 'user-admin';
    public const string REFERENCE_MEMBER = 'user-member';
    public const string REFERENCE_PROSPECT = 'user-prospect';
    public const string REFERENCE_COACH = 'user-coach';

    private const string DEV_PASSWORD = 'speks-dev';

    public function __construct(private readonly UserPasswordHasherInterface $passwordHasher)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $people = [
            [
                'reference' => self::REFERENCE_ADMIN,
                'email' => 'admin@speks.lv',
                'firstName' => 'Anete',
                'lastName' => 'Kalniņa',
                'roles' => ['ROLE_ADMIN'],
                'locale' => 'lv',
            ],
            [
                'reference' => self::REFERENCE_MEMBER,
                'email' => 'member@speks.lv',
                'firstName' => 'Jānis',
                'lastName' => 'Ozols',
                'roles' => [],
                'locale' => 'lv',
            ],
            [
                'reference' => self::REFERENCE_COACH,
                'email' => 'coach@speks.lv',
                'firstName' => 'Artjoms',
                'lastName' => 'Kuzņecovs',
                'roles' => [],
                'locale' => 'ru',
            ],
            [
                'reference' => self::REFERENCE_PROSPECT,
                'email' => 'prospect@speks.lv',
                'firstName' => 'Marina',
                'lastName' => 'Sokolova',
                'roles' => [],
                'locale' => 'ru',
            ],
        ];

        foreach ($people as $data) {
            $user = (new User())
                ->setEmail($data['email'])
                ->setFirstName($data['firstName'])
                ->setLastName($data['lastName'])
                ->setRoles($data['roles'])
                ->setLocale($data['locale']);

            $user->setPassword($this->passwordHasher->hashPassword($user, self::DEV_PASSWORD));

            $manager->persist($user);
            $this->addReference($data['reference'], $user);
        }

        // One membership that is already running, so the account page has
        // something real to render without anyone touching Stripe.
        $membership = new UserMembership(
            $this->getReference(self::REFERENCE_MEMBER, User::class),
            $this->getReference(MembershipPlanFixtures::REFERENCE_ALL_BRANCHES, MembershipPlan::class),
        );
        $membership
            ->setStripeCheckoutSessionId('cs_test_fixture_member')
            ->setStripePaymentIntentId('pi_test_fixture_member')
            ->activate(new DateTimeImmutable('-8 days'));

        $manager->persist($membership);

        $manager->flush();
    }

    /**
     * @return list<class-string>
     */
    public function getDependencies(): array
    {
        return [MembershipPlanFixtures::class];
    }
}
