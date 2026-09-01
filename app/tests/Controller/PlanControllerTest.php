<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\TrainingPlan;
use App\Entity\User;
use App\Repository\TrainingPlanRepository;
use App\Repository\UserRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Reading a programme back.
 *
 * Expects a seeded test database - see {@see BranchControllerTest}. The seeded
 * programme belongs to member@speks.lv and is read here rather than generated,
 * which is the point of AssessmentFixtures: none of this needs the plan
 * service to be running, and CI has no such service.
 *
 * A training plan is health information. Every action is asked whether the
 * plan is this member's, and the tests below are what stops that check being
 * quietly dropped from the second one.
 */
final class PlanControllerTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function localeProvider(): iterable
    {
        yield 'english' => ['en'];
        yield 'latvian' => ['lv'];
        yield 'russian' => ['ru'];
    }

    #[DataProvider('localeProvider')]
    public function testTheSeededProgrammeRendersInEveryLocale(string $locale): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('member@speks.lv'));

        $plan = self::seededPlan();
        $crawler = $client->request('GET', '/'.$locale.'/plan/'.$plan->getId());

        self::assertResponseIsSuccessful();

        // The split, the week count and the days a week, then the programme.
        self::assertSelectorTextContains('body', 'Upper / Lower');
        self::assertSelectorTextContains('body', 'Barbell Bench Press');
        self::assertCount(5, $crawler->filter('details'));

        // Twenty sessions, each with its own table.
        self::assertCount(20, $crawler->filter('table'));
    }

    public function testTheProgrammePageNamesTheDeloadAndCarriesTheDisclaimer(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('member@speks.lv'));

        $client->request('GET', '/en/plan/'.self::seededPlan()->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Deload');
        self::assertSelectorTextContains('body', 'A lighter week on purpose');
        self::assertSelectorTextContains('body', 'not medical advice');
    }

    /**
     * The claim that an AI wrote the prose is made from the stored row, so a
     * plan generated with no API key must not make it.
     */
    public function testAPlanGeneratedWithoutTheProseLayerDoesNotClaimOne(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('member@speks.lv'));

        $plan = self::seededPlan();
        self::assertFalse($plan->isLlmUsed());

        $client->request('GET', '/en/plan/'.$plan->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', 'written by our AI layer');
    }

    public function testAProgrammeThatIsNotYoursIsRefused(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('prospect@speks.lv'));

        $client->request('GET', '/en/plan/'.self::seededPlan()->getId());

        self::assertResponseRedirects('/en/account/plans');

        $client->followRedirect();
        self::assertSelectorTextContains('body', 'That programme is not yours');
    }

    public function testTheDocumentOfAProgrammeThatIsNotYoursIsRefusedToo(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('prospect@speks.lv'));

        $client->request('GET', '/en/plan/'.self::seededPlan()->getId().'/pdf');

        self::assertResponseRedirects('/en/account/plans');
    }

    public function testAProgrammePageIsClosedToAnonymousVisitors(): void
    {
        $client = static::createClient();
        $client->request('GET', '/en/plan/'.self::seededPlan()->getId());

        self::assertResponseRedirects('http://localhost/en/login');
    }

    public function testAPlanThatDoesNotExistIs404(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('member@speks.lv'));

        $client->request('GET', '/en/plan/99999999');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * The service renders the document, so a service that is not answering
     * costs the download and nothing else. This is the state CI is in.
     */
    public function testTheDocumentIsAFlashRatherThanA500WhenTheServiceIsDown(): void
    {
        $client = static::createClient();
        // A client that always refuses to connect, rather than relying on
        // nothing listening on port 8001. CI has no plan service; a developer
        // running the whole stack does, and this test has to assert the same
        // failure either way.
        $client->disableReboot();
        static::getContainer()->set('ai_service.client', new MockHttpClient(
            static function (): never {
                throw new TransportException('Connection refused.');
            },
            'http://ai-service.test',
        ));

        $client->loginUser(self::user('member@speks.lv'));

        $plan = self::seededPlan();
        $client->request('GET', '/en/plan/'.$plan->getId().'/pdf');

        self::assertResponseRedirects('/en/plan/'.$plan->getId());

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'The programme service is not answering right now');
    }

    public function testTheDocumentIsStreamedBackAsAnAttachment(): void
    {
        $client = static::createClient();

        // Swapped before the first request and kept across any further one:
        // the kernel is rebooted between requests otherwise, which would put
        // the real client back.
        $client->disableReboot();
        static::getContainer()->set('ai_service.client', new MockHttpClient(
            new MockResponse("%PDF-1.7\nnot really a document\n", [
                'response_headers' => ['content-type' => 'application/pdf'],
            ]),
            'http://ai-service.test',
        ));

        $client->loginUser(self::user('member@speks.lv'));
        $plan = self::seededPlan();
        $client->request('GET', '/en/plan/'.$plan->getId().'/pdf');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/pdf');
        self::assertResponseHeaderSame(
            'content-disposition',
            sprintf('attachment; filename=speks-training-plan-%d.pdf', $plan->getId()),
        );
        self::assertStringStartsWith('%PDF-', (string) $client->getResponse()->getContent());
    }

    public function testTheAccountListsProgrammesAndIsLinkedFromTheNav(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('member@speks.lv'));

        $crawler = $client->request('GET', '/en/account/plans');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Your training plans');
        self::assertSelectorTextContains('body', 'Upper / Lower');
        self::assertGreaterThan(0, $crawler->filter('a[href="/en/account/plans"]')->count());
        self::assertGreaterThan(0, $crawler->filter('a[href="/en/plan/'.self::seededPlan()->getId().'"]')->count());
    }

    /**
     * A member who has not answered the questionnaire is told what to do about
     * it rather than shown an empty table.
     */
    public function testTheEmptyStateOffersTheQuestionnaire(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('coach@speks.lv'));

        $crawler = $client->request('GET', '/en/account/plans');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'You have not completed the assessment yet');
        self::assertCount(0, $crawler->filter('table'));
        self::assertGreaterThan(0, $crawler->filter('a[href="/en/assessment"]')->count());
    }

    public function testTheAccountPlansPageIsClosedToAnonymousVisitors(): void
    {
        $client = static::createClient();
        $client->request('GET', '/en/account/plans');

        self::assertResponseRedirects('http://localhost/en/login');
    }

    /**
     * The programme AssessmentFixtures seeded for member@speks.lv.
     */
    private static function seededPlan(): TrainingPlan
    {
        $plans = static::getContainer()->get(TrainingPlanRepository::class);
        self::assertInstanceOf(TrainingPlanRepository::class, $plans);

        $seeded = $plans->findForMember(self::user('member@speks.lv'));

        self::assertNotSame([], $seeded, 'AssessmentFixtures has not been loaded into the test database.');

        return $seeded[0];
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
