<?php

declare(strict_types=1);

namespace App\Tests\Plan;

use App\Domain\Enum\Equipment;
use App\Domain\Enum\Experience;
use App\Domain\Enum\Goal;
use App\Entity\Assessment;
use App\Entity\TrainingPlan;
use App\Entity\User;
use App\Plan\AssessmentPayload;
use App\Plan\MedicalReferralResult;
use App\Plan\PlanService;
use App\Plan\PlanServiceUnavailable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The one place this app talks to the plan service, tested without it running.
 *
 * That is not a convenience: the service being unreachable is the state CI is
 * in and the state a fresh clone starts in, so a mocked transport is the only
 * way both the happy path and every failure mode are asserted on every push.
 *
 * Every failure - a refused connection, a 500, a body that is not JSON, a
 * status this app does not know - has to arrive as one PlanServiceUnavailable,
 * because that is the single thing the controllers know how to turn into an
 * honest message instead of a 500.
 */
final class PlanServiceTest extends TestCase
{
    public function testAGeneratedProgrammeComesBackAsAPlanCarryingTheWholePayload(): void
    {
        $client = new MockHttpClient(self::json(self::programme()), 'http://ai-service.test');
        $assessment = self::assessment();

        $plan = (new PlanService($client))->generate($assessment);

        self::assertInstanceOf(TrainingPlan::class, $plan);
        self::assertFalse($plan->isReferral());
        self::assertSame('Upper / Lower', $plan->getSplit());
        self::assertSame('1.0.0', $plan->getEngineVersion());
        self::assertTrue($plan->isLlmUsed());
        self::assertSame(2, $plan->getWeekCount());
        self::assertSame(1, $plan->getDaysPerWeek());
        self::assertSame($assessment, $plan->getAssessment());

        // Read back through the objects a template uses, not the raw array.
        $week = $plan->getWeeks()[1];
        self::assertTrue($week->deload);
        self::assertSame('Upper A', $week->days[0]->label);

        $exercise = $week->days[0]->exercises[0];
        self::assertSame('Barbell Bench Press', $exercise->name);
        self::assertSame(2, $exercise->sets);
        self::assertSame('6-8', $exercise->reps);
        self::assertSame(5, $exercise->rir);
    }

    public function testTheAnswersGoOverTheWireInThePayloadShape(): void
    {
        $response = self::json(self::programme());
        $client = new MockHttpClient($response, 'http://ai-service.test');
        $assessment = self::assessment();

        (new PlanService($client))->generate($assessment);

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame('http://ai-service.test/v1/plan', $response->getRequestUrl());

        $body = $response->getRequestOptions()['body'] ?? null;
        self::assertIsString($body);
        self::assertSame(AssessmentPayload::fromAssessment($assessment), json_decode($body, true));
    }

    public function testAFlaggedAnswerComesBackAsAReferralRatherThanAPlan(): void
    {
        $client = new MockHttpClient(self::json([
            'status' => 'medical_referral',
            'red_flags' => ['chest_pain', 'recent_surgery'],
            'message' => 'Please see a doctor first.',
        ]), 'http://ai-service.test');

        $referral = (new PlanService($client))->generate(self::assessment());

        self::assertInstanceOf(MedicalReferralResult::class, $referral);
        self::assertSame(['chest_pain', 'recent_surgery'], $referral->redFlags);
        self::assertSame('Please see a doctor first.', $referral->message);
    }

    public function testARefusedConnectionIsNotA500(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('Connection refused.');
        }, 'http://ai-service.test');

        $this->expectException(PlanServiceUnavailable::class);

        (new PlanService($client))->generate(self::assessment());
    }

    public function testAServerErrorIsNotA500Either(): void
    {
        $client = new MockHttpClient(new MockResponse('{"detail":"boom"}', ['http_code' => 500]), 'http://ai-service.test');

        $this->expectException(PlanServiceUnavailable::class);

        (new PlanService($client))->generate(self::assessment());
    }

    public function testABodyThatIsNotJsonIsNotAPlan(): void
    {
        $client = new MockHttpClient(new MockResponse('<html>who knows</html>'), 'http://ai-service.test');

        $this->expectException(PlanServiceUnavailable::class);

        (new PlanService($client))->generate(self::assessment());
    }

    public function testAStatusThisAppDoesNotKnowIsRefusedRatherThanStored(): void
    {
        $client = new MockHttpClient(self::json(['status' => 'queued']), 'http://ai-service.test');

        $this->expectException(PlanServiceUnavailable::class);

        (new PlanService($client))->generate(self::assessment());
    }

    public function testThePdfIsTheBytesTheServiceRendered(): void
    {
        $response = new MockResponse("%PDF-1.7\nnot really a document\n", [
            'response_headers' => ['content-type' => 'application/pdf'],
        ]);
        $client = new MockHttpClient($response, 'http://ai-service.test');

        $assessment = self::assessment();
        $plan = TrainingPlan::fromPayload($assessment, self::programme());

        $pdf = (new PlanService($client))->pdf($plan);

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertSame('http://ai-service.test/v1/plan.pdf', $response->getRequestUrl());

        // Generated from the answers, not from the stored programme - that is
        // what makes the page and the document the same plan.
        $body = $response->getRequestOptions()['body'] ?? null;
        self::assertIsString($body);
        self::assertSame(AssessmentPayload::fromAssessment($assessment), json_decode($body, true));
    }

    /**
     * The endpoint answers a flagged assessment with JSON rather than a
     * document. Reaching that from here means the two sides disagree about the
     * screening, which is a fault rather than a download.
     */
    public function testAJsonAnswerToThePdfEndpointIsNotOfferedAsADocument(): void
    {
        $client = new MockHttpClient(self::json([
            'status' => 'medical_referral',
            'red_flags' => ['pregnancy'],
        ]), 'http://ai-service.test');

        $this->expectException(PlanServiceUnavailable::class);

        (new PlanService($client))->pdf(TrainingPlan::fromPayload(self::assessment(), self::programme()));
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private static function json(array $payload): MockResponse
    {
        return new MockResponse(
            json_encode($payload, JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        );
    }

    /**
     * A two-week programme, the second week a deload. Small on purpose: this
     * asserts the reading of the shape, not the engine's programming.
     *
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
                            ['name' => 'Warm-up', 'sets' => 1, 'reps' => '8 min', 'rir' => null, 'notes' => 'Easy cardio.'],
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

    private static function assessment(): Assessment
    {
        $user = (new User())->setEmail('member@speks.lv')->setLocale('lv');

        return (new Assessment($user))
            ->setAge(34)
            ->setHeightCm(182)
            ->setWeightKg(84.5)
            ->setGoal(Goal::MUSCLE_GAIN)
            ->setExperience(Experience::INTERMEDIATE)
            ->setDaysPerWeek(4)
            ->setMinutesPerSession(60)
            ->setEquipment(Equipment::FULL_GYM);
    }
}
