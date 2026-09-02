<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Branch;
use App\Entity\Exercise;
use App\Entity\FloorZone;
use App\Entity\MembershipPlan;
use App\Entity\Trainer;
use App\Entity\User;
use App\Entity\UserMembership;
use App\Repository\FloorZoneRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;

/**
 * People, places, plans and the exercise library: the back office either side
 * of the shop and the booking desk.
 *
 * Requested rather than constructed, for the reason {@see AdminShopCrudTest}
 * records: EasyAdmin fails at runtime rather than at compile time, so nothing
 * short of rendering a page proves that the configureFields() behind it is
 * right. Every screen below is therefore fetched over HTTP.
 *
 * Expects the seeded test database the rest of tests/Controller expects.
 */
final class AdminCrudTest extends WebTestCase
{
    /**
     * Each CRUD's URL prefix against the entity whose rows it lists.
     *
     * The entity is how the detail and edit screens find a real row to point
     * at, so no fixture id is written down here.
     */
    private const array CRUDS = [
        '/admin/user' => User::class,
        '/admin/user-membership' => UserMembership::class,
        '/admin/trainer' => Trainer::class,
        '/admin/membership-plan' => MembershipPlan::class,
        '/admin/floor-zone' => FloorZone::class,
        '/admin/exercise' => Exercise::class,
    ];

    /**
     * Accounts this class creates. Anything at this domain is deleted before
     * and after every test, so the suite can be run twice in a row against a
     * database that is seeded once.
     */
    private const string SCRATCH_DOMAIN = '@test.invalid';

    private const string SCRATCH_EMAIL = 'zane@test.invalid';

    private const string SCRATCH_PASSWORD = 'kettlebell-clean-77';

    /**
     * The one floor zone this class creates, deleted alongside the accounts.
     */
    private const string SCRATCH_SVG_ID = 'scratch-zone';

    protected function setUp(): void
    {
        parent::setUp();
        self::purgeScratchRows();
    }

    protected function tearDown(): void
    {
        self::purgeScratchRows();
        parent::tearDown();
    }

    public function testEveryIndexScreenRendersForStaff(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('admin@speks.lv'));

        foreach (array_keys(self::CRUDS) as $url) {
            $client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }
    }

    /**
     * Detail and edit render different subsets of the same configureFields(),
     * so a field that only breaks on one of them is only caught by asking for
     * both.
     */
    public function testEveryDetailAndEditScreenOfASeededRowRenders(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('admin@speks.lv'));

        foreach (self::CRUDS as $prefix => $entityClass) {
            $row = $prefix.'/'.self::seededIdOf($entityClass);

            $client->request('GET', $row);
            self::assertResponseIsSuccessful($row);

            $client->request('GET', $row.'/edit');
            self::assertResponseIsSuccessful($row.'/edit');
        }
    }

    /**
     * /admin/user-membership/new is deliberately missing from this list. Its
     * controller uses remove(), which only takes the button off the index and
     * leaves the URL answering, and the page then dies inside EasyAdmin's
     * `new ($entityFqcn)()` because a UserMembership cannot be built without a
     * user and a plan. That 500 is reported rather than pinned down here;
     * orders and bookings show the fix, which is disable() over remove().
     */
    public function testTheCrudsThatMayCreateRowsOfferTheirForm(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('admin@speks.lv'));

        foreach ([
            '/admin/user/new',
            '/admin/trainer/new',
            '/admin/membership-plan/new',
            '/admin/floor-zone/new',
            '/admin/exercise/new',
        ] as $url) {
            $client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }
    }

    /**
     * What remove() does achieve: no staff member is invited to type a sale
     * into existence, even though the URL behind the button still answers.
     */
    public function testTheMembershipIndexOffersNoButtonForInventingASale(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('admin@speks.lv'));

        $crawler = $client->request('GET', '/admin/user-membership');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.action-new'));

        // The exercise library keeps its button, so the count above is reading
        // the page rather than a selector that never matches anything.
        $crawler = $client->request('GET', '/admin/exercise');
        self::assertCount(1, $crawler->filter('.action-new'));
    }

    public function testAnOrdinaryMemberIsRefusedEveryCrud(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('member@speks.lv'));

        foreach (array_keys(self::CRUDS) as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    public function testASignedOutVisitorIsSentToTheLoginForm(): void
    {
        $client = static::createClient();

        foreach (array_keys(self::CRUDS) as $url) {
            $client->request('GET', $url);

            // No locale on /admin, so the router falls back to the default one.
            self::assertResponseRedirects('http://localhost/en/login', message: $url);
        }
    }

    /**
     * An svgId names a shape in the floor plan, so it has to survive being put
     * in a CSS selector. An unusable one answers 422, not 200.
     */
    public function testAFloorZoneWithAnUnusableSvgIdIsRefused(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('admin@speks.lv'));

        $crawler = $client->request('GET', '/admin/floor-zone/new');
        self::assertResponseIsSuccessful();

        $client->submit(self::saveForm($crawler, [
            'FloorZone[branch]' => (string) self::seededIdOf(Branch::class),
            'FloorZone[name][en]' => 'Scratch zone',
            'FloorZone[svgId]' => 'Not An Id',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertNull(self::floorZones()->findOneBy(['svgId' => 'Not An Id']));
    }

    /**
     * The same submission with a usable svgId goes through, which is what says
     * the refusal above was about that one box and not about the rest of the
     * form being unfilled.
     */
    public function testAFloorZoneWithAUsableSvgIdIsSaved(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('admin@speks.lv'));

        $crawler = $client->request('GET', '/admin/floor-zone/new');

        $client->submit(self::saveForm($crawler, [
            'FloorZone[branch]' => (string) self::seededIdOf(Branch::class),
            'FloorZone[name][en]' => 'Scratch zone',
            'FloorZone[svgId]' => self::SCRATCH_SVG_ID,
        ]));

        self::assertResponseRedirects('http://localhost/admin/floor-zone');

        $saved = self::floorZones()->findOneBy(['svgId' => self::SCRATCH_SVG_ID]);
        self::assertInstanceOf(FloorZone::class, $saved);
        self::assertSame('Scratch zone', $saved->getName()->get('en'));
    }

    /**
     * The password box is unmapped and write-only, so the only proof that what
     * was typed became the account's password is signing in with it.
     */
    public function testStaffCanCreateAnAccountAndThePasswordTheyTypeIsHashed(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('admin@speks.lv'));

        $created = self::createScratchAccount($client);

        self::assertNotSame(self::SCRATCH_PASSWORD, $created->getPassword());

        // A second client, because the first one is signed in as the admin.
        self::ensureKernelShutdown();
        $visitor = static::createClient();
        $crawler = $visitor->request('GET', '/en/login');

        $visitor->submit($crawler->selectButton('Sign in')->form([
            '_username' => self::SCRATCH_EMAIL,
            '_password' => self::SCRATCH_PASSWORD,
        ]));

        self::assertResponseRedirects('/en/account');
    }

    /**
     * Editing an account without typing a password leaves the stored hash
     * alone - otherwise every correction to a name would lock somebody out.
     */
    public function testSavingAnAccountWithAnEmptyPasswordBoxKeepsTheOldOne(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('admin@speks.lv'));

        $created = self::createScratchAccount($client);
        $id = $created->getId();
        $hash = $created->getPassword();
        self::assertIsInt($id);

        $crawler = $client->request('GET', '/admin/user/'.$id.'/edit');
        self::assertResponseIsSuccessful();

        $client->submit(self::saveForm($crawler, ['User[lastName]' => 'Zālīte']));
        self::assertResponseRedirects('http://localhost/admin/user');

        $saved = self::account(self::SCRATCH_EMAIL);
        self::assertSame('Zālīte', $saved->getLastName());
        self::assertSame($hash, $saved->getPassword());
    }

    public function testAnAccountCannotTakeAnEmailThatIsAlreadyInUse(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('admin@speks.lv'));

        $crawler = $client->request('GET', '/admin/user/new');

        $client->submit(self::saveForm($crawler, [
            'User[email]' => 'admin@speks.lv',
            'User[firstName]' => 'Zane',
            'User[lastName]' => 'Zaķe',
            'User[plainPassword]' => self::SCRATCH_PASSWORD,
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, self::users()->findBy(['email' => 'admin@speks.lv']));
    }

    /**
     * Fills in the back office's own new-account form, which is the only thing
     * that puts the password listener on the user CRUD to work.
     */
    private static function createScratchAccount(KernelBrowser $client): User
    {
        $crawler = $client->request('GET', '/admin/user/new');
        self::assertResponseIsSuccessful();

        $client->submit(self::saveForm($crawler, [
            'User[email]' => self::SCRATCH_EMAIL,
            'User[firstName]' => 'Zane',
            'User[lastName]' => 'Zaķe',
            'User[plainPassword]' => self::SCRATCH_PASSWORD,
        ]));

        self::assertResponseRedirects('http://localhost/admin/user');

        return self::account(self::SCRATCH_EMAIL);
    }

    /**
     * EasyAdmin renders its save buttons outside the form they submit and
     * gives two of them the same label, so the one that saves and goes back to
     * the list is picked by value rather than by what it says.
     *
     * @param array<string, string> $values
     */
    private static function saveForm(Crawler $crawler, array $values): Form
    {
        return $crawler->filter('button[value="saveAndReturn"]')->form($values);
    }

    /**
     * The lowest id in a table, which is a seeded row rather than anything a
     * later test wrote.
     */
    private static function seededIdOf(string $entityClass): int
    {
        $id = self::entityManager()
            ->createQuery('SELECT MIN(e.id) FROM '.$entityClass.' e')
            ->getSingleScalarResult();

        self::assertIsInt($id);

        return $id;
    }

    private static function user(string $email): User
    {
        $user = self::users()->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private static function account(string $email): User
    {
        $user = self::users()->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private static function users(): UserRepository
    {
        $repository = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);

        return $repository;
    }

    private static function floorZones(): FloorZoneRepository
    {
        $repository = static::getContainer()->get(FloorZoneRepository::class);
        self::assertInstanceOf(FloorZoneRepository::class, $repository);

        return $repository;
    }

    private static function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    /**
     * Removes everything this class writes, so it leaves the seeded database
     * exactly as it found it.
     */
    private static function purgeScratchRows(): void
    {
        self::bootKernel();

        $entityManager = self::entityManager();

        $entityManager->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :domain')
            ->setParameter('domain', '%'.self::SCRATCH_DOMAIN)
            ->execute();

        $entityManager->createQuery('DELETE FROM '.FloorZone::class.' z WHERE z.svgId = :svgId')
            ->setParameter('svgId', self::SCRATCH_SVG_ID)
            ->execute();

        self::ensureKernelShutdown();
    }
}
