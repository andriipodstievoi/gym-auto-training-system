<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Conversation;
use App\Entity\User;
use App\Repository\ConversationRepository;
use App\Repository\MessageRepository;
use App\Repository\TrainerRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Messaging, from both ends of a thread.
 *
 * Artjoms is the one seeded coach with a login, so he is the only one who can
 * be written to as well as about - which is the point of the last test here:
 * a coach with no account must not break the send.
 */
final class MessageControllerTest extends WebTestCase
{
    public function testMessagesAreClosedToAnonymousVisitors(): void
    {
        $client = static::createClient();
        $client->request('GET', '/en/messages');

        self::assertResponseRedirects('http://localhost/en/login');
    }

    public function testTheThreadListShowsTheOtherPartyForBothSides(): void
    {
        $client = static::createClient();

        $client->loginUser(self::user('member@speks.lv'));
        $client->request('GET', '/en/messages');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Artjoms Kuzņecovs');

        // The same thread, read by the coach, is headed by the member.
        $client->loginUser(self::user('coach@speks.lv'));
        $client->request('GET', '/en/messages');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Jānis Ozols');
    }

    public function testOpeningAThreadMarksTheOtherPartyMessagesRead(): void
    {
        $client = static::createClient();

        // The coach writes, so the member has something unread.
        $client->loginUser(self::user('coach@speks.lv'));
        $conversation = self::thread('member@speks.lv');
        $crawler = $client->request('GET', '/en/messages/'.$conversation->getId());

        $client->submit($crawler->selectButton('Send')->form(['body' => 'Bring your notes on Thursday.']));
        self::assertResponseRedirects();

        $member = self::user('member@speks.lv');
        self::assertGreaterThan(0, self::unreadFor($member));

        $client->loginUser($member);
        $client->request('GET', '/en/messages/'.$conversation->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Bring your notes on Thursday.');
        self::assertSame(0, self::unreadFor(self::user('member@speks.lv')));
    }

    /**
     * A member's own message never counts against them, however long it sits
     * there without an answer.
     */
    public function testYourOwnMessagesAreNeverUnreadForYou(): void
    {
        $client = static::createClient();
        $member = self::user('member@speks.lv');
        $client->loginUser($member);

        $conversation = self::thread('member@speks.lv');
        $crawler = $client->request('GET', '/en/messages/'.$conversation->getId());
        $client->submit($crawler->selectButton('Send')->form(['body' => 'Any chance of a Saturday?']));

        self::assertSame(0, self::unreadFor(self::user('member@speks.lv')));
    }

    public function testAMemberStartsAThreadFromTheCoachProfile(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('prospect@speks.lv'));

        $crawler = $client->request('GET', '/en/trainers/artjoms-kuznecovs');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[action="/en/messages/send"]')->form();
        $form['body'] = 'Do you coach in Russian on weekday evenings?';
        $client->submit($form);

        self::assertResponseRedirects();

        // The coach has a login, so there is somebody to notify.
        self::assertEmailCount(1);

        $conversation = self::pair('artjoms-kuznecovs', 'prospect@speks.lv');
        self::assertNotNull($conversation);
        self::assertGreaterThan(0, $conversation->getMessages()->count());
    }

    /**
     * A second message to the same coach continues the thread rather than
     * opening a rival one - the unique constraint would refuse anyway.
     */
    public function testWritingTwiceKeepsOneThread(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('prospect@speks.lv'));

        $crawler = $client->request('GET', '/en/trainers/artjoms-kuznecovs');
        $form = $crawler->filter('form[action="/en/messages/send"]')->form();
        $form['body'] = 'Following up on the evenings question.';
        $client->submit($form);

        self::assertResponseRedirects();

        $conversations = static::getContainer()->get(ConversationRepository::class);
        self::assertInstanceOf(ConversationRepository::class, $conversations);

        $trainer = self::trainers()->findOneActiveBySlug('artjoms-kuznecovs');
        self::assertNotNull($trainer);

        self::assertCount(1, $conversations->findBy([
            'trainer' => $trainer,
            'member' => self::user('prospect@speks.lv'),
        ]));
    }

    /**
     * A coach with no login has nowhere to be written to. The message is still
     * taken; nothing dereferences the missing account.
     */
    public function testWritingToACoachWithNoAccountSendsNoMailAndStillWorks(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('member@speks.lv'));

        $crawler = $client->request('GET', '/en/trainers/marta-ozola');
        $form = $crawler->filter('form[action="/en/messages/send"]')->form();
        $form['body'] = 'Is the rehab work done on the main floor?';
        $client->submit($form);

        self::assertResponseRedirects();
        self::assertEmailCount(0);
        self::assertNotNull(self::pair('marta-ozola', 'member@speks.lv'));
    }

    public function testAThreadIsInvisibleToSomebodyWhoIsNotInIt(): void
    {
        $client = static::createClient();
        $conversation = self::thread('member@speks.lv');

        $client->loginUser(self::user('admin@speks.lv'));
        $client->request('GET', '/en/messages/'.$conversation->getId());

        self::assertResponseStatusCodeSame(404);
    }

    public function testSomebodyOutsideAThreadCannotWriteIntoIt(): void
    {
        $client = static::createClient();
        $conversation = self::thread('member@speks.lv');
        $before = $conversation->getMessages()->count();

        // The outsider's own message list is empty, so the token comes off a
        // coach profile - which is exactly where a forged request would get
        // one from too.
        $client->loginUser(self::user('admin@speks.lv'));
        $crawler = $client->request('GET', '/en/trainers/artjoms-kuznecovs');

        $client->request('POST', '/en/messages/send', [
            '_token' => self::tokenIn($crawler),
            'conversation' => (string) $conversation->getId(),
            'body' => 'Let me in.',
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame($before, self::thread('member@speks.lv')->getMessages()->count());
    }

    public function testAPostWithoutAValidTokenSendsNothing(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('member@speks.lv'));

        $conversation = self::thread('member@speks.lv');
        $before = $conversation->getMessages()->count();

        $client->request('GET', '/en/messages/'.$conversation->getId());
        $client->request('POST', '/en/messages/send', [
            '_token' => 'not-the-token',
            'conversation' => (string) $conversation->getId(),
            'body' => 'Sneaking one in.',
        ]);

        self::assertResponseRedirects('/en/messages');
        self::assertSame($before, self::thread('member@speks.lv')->getMessages()->count());
    }

    /**
     * The token a page rendered. Requesting the page also gives BrowserKit the
     * history a Referer needs.
     */
    private static function tokenIn(Crawler $crawler): string
    {
        $field = $crawler->filter('input[name="_token"]')->first();

        self::assertGreaterThan(0, $field->count(), 'The page rendered no CSRF token.');

        return $field->attr('value') ?? '';
    }

    private static function thread(string $memberEmail): Conversation
    {
        $conversation = self::pair('artjoms-kuznecovs', $memberEmail);
        self::assertInstanceOf(Conversation::class, $conversation);

        return $conversation;
    }

    private static function pair(string $slug, string $memberEmail): ?Conversation
    {
        self::entityManager()->clear();

        $trainer = self::trainers()->findOneActiveBySlug($slug);
        self::assertNotNull($trainer);

        $conversations = static::getContainer()->get(ConversationRepository::class);
        self::assertInstanceOf(ConversationRepository::class, $conversations);

        return $conversations->findOnePair($trainer, self::user($memberEmail));
    }

    private static function unreadFor(User $user): int
    {
        self::entityManager()->clear();

        $messages = static::getContainer()->get(MessageRepository::class);
        self::assertInstanceOf(MessageRepository::class, $messages);

        return $messages->countUnreadFor($user);
    }

    private static function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private static function trainers(): TrainerRepository
    {
        $trainers = static::getContainer()->get(TrainerRepository::class);
        self::assertInstanceOf(TrainerRepository::class, $trainers);

        return $trainers;
    }

    private static function user(string $email): User
    {
        $users = static::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);

        $user = $users->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
