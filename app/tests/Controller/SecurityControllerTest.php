<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Registration, sign-in, sign-out and who may reach the back office.
 *
 * Expects a seeded test database - see {@see BranchControllerTest}. The three
 * seeded accounts all use the password below.
 */
final class SecurityControllerTest extends WebTestCase
{
    use MailerAssertionsTrait;

    private const string FIXTURE_PASSWORD = 'speks-dev';

    /**
     * Accounts these tests create. Anything at this domain is deleted before
     * and after, so the suite can be run twice in a row against one database.
     */
    private const string SCRATCH_DOMAIN = '@test.invalid';

    protected function setUp(): void
    {
        parent::setUp();
        self::purgeScratchAccounts();
    }

    protected function tearDown(): void
    {
        self::purgeScratchAccounts();
        parent::tearDown();
    }

    #[DataProvider('localeProvider')]
    public function testLoginPageRendersInEveryLocale(string $locale, string $expected): void
    {
        $client = static::createClient();
        $client->request('GET', '/'.$locale.'/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $expected);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function localeProvider(): iterable
    {
        yield 'english' => ['en', 'Sign in'];
        yield 'latvian' => ['lv', 'Pieslēgties'];
        yield 'russian' => ['ru', 'Вход'];
    }

    public function testRegistrationCreatesAnAccountAndSignsItIn(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/en/register');
        self::assertResponseIsSuccessful();

        $client->submit($crawler->selectButton('Create account')->form([
            'registration_form[firstName]' => 'Elza',
            'registration_form[lastName]' => 'Liepa',
            'registration_form[email]' => 'elza'.self::SCRATCH_DOMAIN,
            'registration_form[plainPassword][first]' => 'kettlebell-swing-42',
            'registration_form[plainPassword][second]' => 'kettlebell-swing-42',
        ]));

        self::assertResponseRedirects('/en/account');

        // Asserted before the redirect is followed: the kernel reboots between
        // requests, and the collected mailer events go with it.
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailHeaderSame($email, 'To', 'Elza Liepa <elza'.self::SCRATCH_DOMAIN.'>');
        self::assertEmailHeaderSame($email, 'Subject', 'Welcome to SPĒKS');

        $user = self::userRepository()->findOneByEmail('elza'.self::SCRATCH_DOMAIN);
        self::assertInstanceOf(User::class, $user);
        self::assertSame(['ROLE_USER'], $user->getRoles());

        // The password is stored hashed, never as typed.
        self::assertNotSame('kettlebell-swing-42', $user->getPassword());
        self::assertStringStartsWith('$', $user->getPassword());

        // Registering signs you straight in rather than bouncing you to a form.
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Elza');
    }

    public function testRegistrationRefusesAnEmailThatIsAlreadyTaken(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/en/register');

        $client->submit($crawler->selectButton('Create account')->form([
            'registration_form[firstName]' => 'Imposter',
            'registration_form[lastName]' => 'Ozols',
            'registration_form[email]' => 'member@speks.lv',
            'registration_form[plainPassword][first]' => 'kettlebell-swing-42',
            'registration_form[plainPassword][second]' => 'kettlebell-swing-42',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'An account with this email already exists');
    }

    public function testRegistrationRefusesMismatchedPasswords(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/en/register');

        $client->submit($crawler->selectButton('Create account')->form([
            'registration_form[firstName]' => 'Elza',
            'registration_form[lastName]' => 'Liepa',
            'registration_form[email]' => 'mismatch'.self::SCRATCH_DOMAIN,
            'registration_form[plainPassword][first]' => 'kettlebell-swing-42',
            'registration_form[plainPassword][second]' => 'something-else-99',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'The two passwords do not match');
        self::assertNull(self::userRepository()->findOneByEmail('mismatch'.self::SCRATCH_DOMAIN));
    }

    public function testRegistrationRefusesAShortPassword(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/en/register');

        $client->submit($crawler->selectButton('Create account')->form([
            'registration_form[firstName]' => 'Elza',
            'registration_form[lastName]' => 'Liepa',
            'registration_form[email]' => 'short'.self::SCRATCH_DOMAIN,
            'registration_form[plainPassword][first]' => 'short',
            'registration_form[plainPassword][second]' => 'short',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Use at least 8 characters');
        self::assertNull(self::userRepository()->findOneByEmail('short'.self::SCRATCH_DOMAIN));
    }

    public function testSigningInWithTheRightPasswordReachesTheAccount(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/en/login');

        $client->submit($crawler->selectButton('Sign in')->form([
            '_username' => 'member@speks.lv',
            '_password' => self::FIXTURE_PASSWORD,
        ]));

        self::assertResponseRedirects('/en/account');
        $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Jānis');
    }

    public function testSigningInWithTheWrongPasswordIsRefused(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/en/login');

        $client->submit($crawler->selectButton('Sign in')->form([
            '_username' => 'member@speks.lv',
            '_password' => 'not-the-password',
        ]));

        $client->followRedirect();
        self::assertSelectorExists('[role="alert"]');
        self::assertNull(static::getContainer()->get('security.token_storage')->getToken());
    }

    public function testSigningOutEndsTheSession(): void
    {
        $client = static::createClient();
        $client->loginUser(self::member());

        $client->request('GET', '/en/account');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/en/logout');
        self::assertResponseRedirects();

        // The account is behind the firewall again.
        $client->request('GET', '/en/account');
        self::assertResponseRedirects('http://localhost/en/login');
    }

    public function testAccountIsClosedToAnonymousVisitors(): void
    {
        $client = static::createClient();
        $client->request('GET', '/en/account');

        self::assertResponseRedirects('http://localhost/en/login');
    }

    public function testAdminSendsAnonymousVisitorsToTheLoginForm(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin');

        // No locale on /admin, so the router falls back to the default one.
        self::assertResponseRedirects('http://localhost/en/login');
    }

    public function testAdminIsForbiddenToOrdinaryMembers(): void
    {
        $client = static::createClient();
        $client->loginUser(self::member());

        $client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminOpensForStaff(): void
    {
        $client = static::createClient();
        $client->loginUser(self::admin());

        $client->request('GET', '/admin');

        // The dashboard forwards to the first CRUD screen.
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testThePlaceholderAdminAccountIsGone(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin', server: [
            'PHP_AUTH_USER' => 'admin',
            'PHP_AUTH_PW' => 'speks-dev',
        ]);

        // HTTP basic against an in-memory user was the M1 placeholder. It must
        // no longer open anything.
        self::assertResponseRedirects('http://localhost/en/login');
    }

    private static function userRepository(): UserRepository
    {
        $repository = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);

        return $repository;
    }

    private static function member(): User
    {
        $user = self::userRepository()->findOneByEmail('member@speks.lv');
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private static function admin(): User
    {
        $user = self::userRepository()->findOneByEmail('admin@speks.lv');
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    /**
     * Removes every account this class creates, so the suite is repeatable
     * against a database that is seeded once.
     */
    private static function purgeScratchAccounts(): void
    {
        self::bootKernel();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $entityManager->createQuery(
            'DELETE FROM '.User::class.' u WHERE u.email LIKE :domain'
        )->setParameter('domain', '%'.self::SCRATCH_DOMAIN)->execute();

        self::ensureKernelShutdown();
    }
}
