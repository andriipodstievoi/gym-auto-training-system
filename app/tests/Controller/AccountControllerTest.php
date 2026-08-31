<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The member's own area. Expects a seeded test database - see
 * {@see BranchControllerTest}.
 */
final class AccountControllerTest extends WebTestCase
{
    protected function tearDown(): void
    {
        // Several tests here edit the seeded member. Put them back so the
        // suite does not depend on the order it runs in.
        self::restoreSeededMember();
        parent::tearDown();
    }

    public function testTheOverviewShowsTheMembershipTheMemberHolds(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('member@speks.lv'));

        $crawler = $client->request('GET', '/en/account');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Jānis');
        self::assertSelectorTextContains('body', 'All branches');
        self::assertSelectorTextContains('body', 'Active');
        self::assertStringContainsString('44.90', $crawler->filter('body')->text());
    }

    public function testAMemberWithoutAMembershipIsToldSo(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('prospect@speks.lv'));

        $client->request('GET', '/en/account');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'You do not have an active membership yet');
    }

    #[DataProvider('localeProvider')]
    public function testTheAccountRendersInEveryLocale(string $locale, string $expected): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('member@speks.lv'));

        $client->request('GET', '/'.$locale.'/account');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $expected);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function localeProvider(): iterable
    {
        yield 'english' => ['en', 'Your membership'];
        yield 'latvian' => ['lv', 'Tavs abonements'];
        yield 'russian' => ['ru', 'Твой абонемент'];
    }

    public function testTheProfileFormSavesNameAndLanguage(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('member@speks.lv'));

        $crawler = $client->request('GET', '/en/account/profile');
        self::assertResponseIsSuccessful();

        $client->submit($crawler->selectButton('Save details')->form([
            'profile_form[firstName]' => 'Jānis Kārlis',
            'profile_form[lastName]' => 'Ozols',
            'profile_form[email]' => 'member@speks.lv',
            'profile_form[locale]' => 'ru',
        ]));

        // Saving a new language follows the member into it.
        self::assertResponseRedirects('/ru/account/profile');

        $saved = self::user('member@speks.lv');
        self::assertSame('Jānis Kārlis', $saved->getFirstName());
        self::assertSame('ru', $saved->getLocale());
    }

    public function testChangingThePasswordRequiresTheCurrentOne(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('member@speks.lv'));

        $crawler = $client->request('GET', '/en/account/profile');

        $client->submit($crawler->selectButton('Change password')->form([
            'change_password_form[currentPassword]' => 'not-the-password',
            'change_password_form[newPassword][first]' => 'barbell-row-77',
            'change_password_form[newPassword][second]' => 'barbell-row-77',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'That is not your current password');
    }

    public function testChangingThePasswordStoresANewHash(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('member@speks.lv'));

        $before = self::user('member@speks.lv')->getPassword();

        $crawler = $client->request('GET', '/en/account/profile');
        $client->submit($crawler->selectButton('Change password')->form([
            'change_password_form[currentPassword]' => 'speks-dev',
            'change_password_form[newPassword][first]' => 'barbell-row-77',
            'change_password_form[newPassword][second]' => 'barbell-row-77',
        ]));

        self::assertResponseRedirects('/en/account/profile');

        $after = self::user('member@speks.lv')->getPassword();
        self::assertNotSame($before, $after);
        self::assertNotSame('barbell-row-77', $after);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        self::assertTrue($hasher->isPasswordValid(self::user('member@speks.lv'), 'barbell-row-77'));
    }

    public function testTheProfileIsClosedToAnonymousVisitors(): void
    {
        $client = static::createClient();
        $client->request('GET', '/en/account/profile');

        self::assertResponseRedirects('http://localhost/en/login');
    }

    private static function user(string $email): User
    {
        $repository = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);

        $user = $repository->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    /**
     * Resets the seeded member to the state the fixtures leave them in.
     */
    private static function restoreSeededMember(): void
    {
        self::bootKernel();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        $user = self::user('member@speks.lv');
        $user->setFirstName('Jānis')
            ->setLastName('Ozols')
            ->setLocale('lv')
            ->setPassword($hasher->hashPassword($user, 'speks-dev'));

        $entityManager->flush();

        self::ensureKernelShutdown();
    }
}
