<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\Enum\PlanStatus;
use App\Entity\Assessment;
use App\Entity\TrainingPlan;
use App\Entity\User;
use App\Repository\AssessmentRepository;
use App\Repository\TrainingPlanRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The questionnaire, end to end.
 *
 * Expects a seeded test database - see {@see BranchControllerTest}.
 *
 * Everything that writes uses prospect@speks.lv and clears that member's rows
 * first: member@speks.lv owns the seeded assessment and plan that the plan
 * pages are read through, and a test that deleted those would break a
 * different test file depending on the order they ran in.
 *
 * The plan service is not running for any of this, which is exactly how CI
 * runs it. The one test that needs a real programme swaps the scoped HTTP
 * client for a MockHttpClient rather than starting a second runtime.
 */
final class AssessmentControllerTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function localeProvider(): iterable
    {
        yield 'english' => ['en', 'Build my programme'];
        yield 'latvian' => ['lv', 'Izveido manu programmu'];
        yield 'russian' => ['ru', 'Собрать мою программу'];
    }

    #[DataProvider('localeProvider')]
    public function testTheQuestionnaireRendersInEveryLocale(string $locale, string $expected): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('member@speks.lv'));

        $crawler = $client->request('GET', '/'.$locale.'/assessment');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $expected);

        // One form, one POST: a step is a section of this page, not a request
        // of its own.
        self::assertCount(1, $crawler->filter('form[action="/'.$locale.'/assessment"]'));
    }

    public function testTheQuestionnaireIsClosedToAnonymousVisitors(): void
    {
        $client = static::createClient();
        $client->request('GET', '/en/assessment');

        self::assertResponseRedirects('http://localhost/en/login');
    }

    /**
     * Lighthouse has found a real labelling defect in three milestones running,
     * so the rule is asserted here rather than waited for: every control on the
     * questionnaire has a label pointing at it, and every group of them sits in
     * a fieldset with a legend.
     */
    public function testEveryControlIsLabelledAndEveryGroupHasALegend(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('member@speks.lv'));

        $crawler = $client->request('GET', '/en/assessment');

        $labelled = $crawler->filter('label[for]')->each(static fn (Crawler $node): string => $node->attr('for') ?? '');

        $controls = $crawler->filter('form input, form select, form textarea')->each(
            static fn (Crawler $node): string => 'hidden' === $node->attr('type') ? '' : ($node->attr('id') ?? ''),
        );

        foreach (array_filter($controls) as $id) {
            self::assertContains($id, $labelled, sprintf('The control "%s" has no <label for>.', $id));
        }

        // The eight screening questions are asked one at a time, each posting
        // under the name Pydantic reads and each with a label of its own.
        foreach (Assessment::PAR_Q_FIELDS as $field) {
            $question = $crawler->filter(sprintf('input[type="checkbox"][name="assessment[%s]"]', $field));

            self::assertCount(1, $question, sprintf('The questionnaire does not ask "%s".', $field));
            self::assertContains($question->attr('id') ?? '', $labelled);
        }

        // Radios and checkboxes are grouped, and every group is named.
        self::assertSame(
            $crawler->filter('fieldset')->count(),
            $crawler->filter('fieldset > legend')->count(),
        );
        self::assertGreaterThan(4, $crawler->filter('fieldset')->count());
    }

    /**
     * The path CI takes: the answers are good, the service is not there.
     *
     * Nothing may be written. An assessment with no programme is a row no page
     * can show, and a plan row with no plan in it is worse.
     */
    public function testAValidSubmissionWithTheServiceDownLeavesNothingBehind(): void
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

        $client->loginUser(self::user('prospect@speks.lv'));
        self::purge('prospect@speks.lv');

        $client->request('POST', '/en/assessment', self::post(self::token($client)));

        self::assertResponseStatusCodeSame(503);
        self::assertSelectorTextContains('body', 'The programme service is not answering right now');

        self::assertSame(0, self::countAssessments('prospect@speks.lv'));
        self::assertSame(0, self::countPlans('prospect@speks.lv'));

        // And the answers are still on the page, so "try again in a moment"
        // costs a click rather than the whole questionnaire.
        self::assertSame('34', self::valueOf($client, 'assessment[age]'));
    }

    /**
     * @return iterable<string, array{string, string|int}>
     */
    public static function outOfRangeProvider(): iterable
    {
        yield 'a child' => ['age', 9];
        yield 'a very old member' => ['age', 130];
        yield 'an impossible height' => ['heightCm', 40];
        yield 'an impossible weight' => ['weightKg', 12];
        yield 'seven days a week' => ['daysPerWeek', 7];
        yield 'a ten minute session' => ['minutesPerSession', 10];
    }

    /**
     * Every range here is a range Pydantic enforces too. A value FastAPI would
     * refuse must never leave this app, because a 422 arriving from a service
     * the member cannot see reads to them as the site being broken.
     */
    #[DataProvider('outOfRangeProvider')]
    public function testAnAnswerTheEngineWouldRefuseIsRefusedHere(string $field, string|int $value): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('prospect@speks.lv'));
        self::purge('prospect@speks.lv');

        $answers = self::post(self::token($client));
        $answers['assessment'][$field] = (string) $value;

        $client->request('POST', '/en/assessment', $answers);

        // 422, not 200: an unusable submission is not the page it asked for.
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, self::countAssessments('prospect@speks.lv'));
    }

    /**
     * The gate runs on this side too. A flagged member gets their answer
     * without those answers ever crossing the wire - which is the whole point,
     * since the service is not running here at all and this still works.
     */
    public function testAFlaggedScreeningProducesAReferralWithoutCallingTheService(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('prospect@speks.lv'));
        self::purge('prospect@speks.lv');

        $answers = self::post(self::token($client));
        $answers['assessment']['chest_pain'] = '1';
        $answers['assessment']['recent_surgery'] = '1';

        $client->request('POST', '/en/assessment', $answers);

        self::assertResponseRedirects();

        $plan = self::latestPlan('prospect@speks.lv');
        self::assertNotNull($plan);
        self::assertSame(PlanStatus::MEDICAL_REFERRAL, $plan->getStatus());
        self::assertSame(['chest_pain', 'recent_surgery'], $plan->getRedFlags());
        self::assertSame('', $plan->getEngineVersion());
        self::assertSame([], $plan->getWeeks());

        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Please see a doctor first');
        // It reads as a safety step: the questions raised are named back.
        self::assertSelectorTextContains('body', 'Do you feel pain in your chest');
        self::assertSelectorTextContains('body', 'This is not a refusal');
        self::assertCount(0, $client->getCrawler()->filter('a[href$="/pdf"]'));

        self::purge('prospect@speks.lv');
    }

    public function testAPostWithoutAValidTokenStoresNothing(): void
    {
        $client = static::createClient();
        $client->loginUser(self::user('prospect@speks.lv'));
        self::purge('prospect@speks.lv');

        self::token($client);
        $client->request('POST', '/en/assessment', self::post('not-the-token'));

        self::assertResponseRedirects('/en/assessment');
        self::assertSame(0, self::countAssessments('prospect@speks.lv'));

        $client->followRedirect();
        self::assertSelectorTextContains('body', 'That form expired');
    }

    /**
     * The success path, with the plan service replaced rather than started.
     */
    public function testAGeneratedProgrammeIsStoredAndRendered(): void
    {
        $client = static::createClient();

        // Swapped before the first request and kept across the second: the
        // kernel is normally rebooted between requests, which would put the
        // real client back before the POST that needs the mock.
        $client->disableReboot();
        static::getContainer()->set('ai_service.client', new MockHttpClient(
            new MockResponse(
                json_encode(self::programme(), JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'application/json']],
            ),
            'http://ai-service.test',
        ));

        $client->loginUser(self::user('prospect@speks.lv'));
        self::purge('prospect@speks.lv');

        $client->request('POST', '/en/assessment', self::post(self::token($client)));

        self::assertResponseRedirects();

        $plan = self::latestPlan('prospect@speks.lv');
        self::assertNotNull($plan);
        self::assertSame(PlanStatus::OK, $plan->getStatus());
        self::assertSame('Upper / Lower', $plan->getSplit());
        self::assertSame('1.0.0', $plan->getEngineVersion());
        self::assertTrue($plan->isLlmUsed());

        $assessment = $plan->getAssessment();
        self::assertSame(34, $assessment->getAge());
        self::assertSame(84.5, $assessment->getWeightKg());
        self::assertSame(['lower_back'], $assessment->getLimitations());
        self::assertSame(['Burpees', 'Box jumps'], $assessment->getDislikedExercises());
        // Answered in English, whatever the account's own language is.
        self::assertSame('en', $assessment->getLocale());
        self::assertFalse($assessment->hasRedFlags());

        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Barbell Bench Press');
        self::assertSelectorTextContains('body', 'Deload');
        // Claimed only because the stored row says the prose layer ran.
        self::assertSelectorTextContains('body', 'written by our AI layer');

        self::purge('prospect@speks.lv');
    }

    /**
     * A valid, complete set of answers, as the form posts them.
     *
     * @return array{_token: string, assessment: array<string, mixed>}
     */
    private static function post(string $token): array
    {
        return [
            '_token' => $token,
            'assessment' => [
                'age' => '34',
                'heightCm' => '182',
                'weightKg' => '84.5',
                'goal' => 'muscle_gain',
                'experience' => 'intermediate',
                'daysPerWeek' => '4',
                'minutesPerSession' => '60',
                'equipment' => 'full_gym',
                'limitations' => ['lower_back'],
                'dislikedExercises' => "Burpees\nBox jumps\n",
            ],
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function programme(): array
    {
        return [
            'status' => 'ok',
            'generated_at' => '2026-09-01T09:00:00Z',
            'engine_version' => '1.0.0',
            'llm_used' => true,
            'split' => 'Upper / Lower',
            'weeks' => [
                [
                    'index' => 1,
                    'deload' => false,
                    'days' => [[
                        'index' => 1,
                        'label' => 'Upper A',
                        'exercises' => [
                            ['name' => 'Barbell Bench Press', 'sets' => 4, 'reps' => '6-8', 'rir' => 2, 'notes' => ''],
                        ],
                    ]],
                ],
                [
                    'index' => 2,
                    'deload' => true,
                    'days' => [[
                        'index' => 1,
                        'label' => 'Upper A',
                        'exercises' => [
                            ['name' => 'Barbell Bench Press', 'sets' => 2, 'reps' => '6-8', 'rir' => 5, 'notes' => ''],
                        ],
                    ]],
                ],
            ],
            'coaching_notes' => 'Add a little load once you reach the top of a rep range.',
            'disclaimer' => 'General fitness guidance, not medical advice.',
        ];
    }

    /**
     * A GET of the questionnaire, and the token it rendered.
     *
     * Doubles as the preceding request a synthesised POST needs: without one
     * there is no history, so BrowserKit sends no Referer.
     */
    private static function token(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/en/assessment');

        self::assertResponseIsSuccessful();

        $field = $crawler->filter('input[name="_token"]')->first();
        self::assertGreaterThan(0, $field->count(), 'The questionnaire rendered no CSRF token.');

        return $field->attr('value') ?? '';
    }

    private static function valueOf(KernelBrowser $client, string $name): string
    {
        $field = $client->getCrawler()->filter(sprintf('input[name="%s"]', $name))->first();

        self::assertGreaterThan(0, $field->count(), sprintf('No field called "%s" on the page.', $name));

        return $field->attr('value') ?? '';
    }

    private static function countAssessments(string $email): int
    {
        $assessments = static::getContainer()->get(AssessmentRepository::class);
        self::assertInstanceOf(AssessmentRepository::class, $assessments);

        return count($assessments->findBy(['user' => self::user($email)]));
    }

    private static function countPlans(string $email): int
    {
        return count(self::plans()->findForMember(self::user($email)));
    }

    private static function latestPlan(string $email): ?TrainingPlan
    {
        self::entityManager()->clear();

        return self::plans()->findForMember(self::user($email))[0] ?? null;
    }

    /**
     * Wipes one member's questionnaires and everything generated from them.
     *
     * The test database is seeded once and never rolled back, so a row left by
     * yesterday's run would otherwise decide what "the latest plan" means.
     */
    private static function purge(string $email): void
    {
        $entityManager = self::entityManager();

        $entityManager->createQuery('DELETE FROM App\Entity\TrainingPlan p WHERE p.user = :user')
            ->setParameter('user', self::user($email))
            ->execute();
        $entityManager->createQuery('DELETE FROM App\Entity\Assessment a WHERE a.user = :user')
            ->setParameter('user', self::user($email))
            ->execute();

        $entityManager->clear();
    }

    private static function plans(): TrainingPlanRepository
    {
        $plans = static::getContainer()->get(TrainingPlanRepository::class);
        self::assertInstanceOf(TrainingPlanRepository::class, $plans);

        return $plans;
    }

    private static function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
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
