<?php

declare(strict_types=1);

namespace App\Plan;

use App\Domain\Enum\PlanStatus;
use App\Entity\Assessment;
use App\Entity\TrainingPlan;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Everything this app knows about the training-plan service.
 *
 * This is the only PHP that talks to ai-service, and the scoped client it uses
 * carries the base URI and the timeout, so nothing here builds a URL or
 * decides how long to wait - see config/packages/framework.yaml.
 *
 * The service being unreachable is the state CI runs in and the state a fresh
 * clone starts in, so every failure mode - a refused connection, a timeout, a
 * 500, a body that is not JSON - leaves here as one PlanServiceUnavailable for
 * the controller to turn into a flash. Nothing from the transport is allowed
 * to escape as a 500, on the same reasoning as StripeCheckout.
 */
final class PlanService
{
    public function __construct(
        #[Autowire(service: 'ai_service.client')]
        private readonly HttpClientInterface $client,
    ) {
    }

    /**
     * Turns a completed questionnaire into a programme.
     *
     * A referral comes back as a result rather than an entity, because whether
     * to store one is the caller's decision - and because the screening gate
     * runs on this side too, so most referrals never get this far.
     *
     * @throws PlanServiceUnavailable when the service cannot be reached or did
     *                                not answer with a plan
     */
    public function generate(Assessment $assessment): TrainingPlan|MedicalReferralResult
    {
        $payload = $this->post('/v1/plan', $assessment);

        $status = PayloadReader::string($payload['status'] ?? null);

        if (PlanStatus::MEDICAL_REFERRAL->value === $status) {
            return MedicalReferralResult::fromPayload($payload);
        }

        if (PlanStatus::OK->value !== $status) {
            throw new PlanServiceUnavailable(sprintf('The plan service answered with status "%s", which is not a status this app knows.', $status));
        }

        return TrainingPlan::fromPayload($assessment, $payload);
    }

    /**
     * The same programme, laid out for printing.
     *
     * The service renders from the assessment rather than from the plan, so
     * the answers go back over the wire instead of the programme coming home.
     * That is what makes the PDF and the page the same document: both are
     * generated from the same inputs by the same deterministic engine.
     *
     * @return string the PDF bytes
     *
     * @throws PlanServiceUnavailable when the service cannot be reached, or
     *                                answers with something that is not a PDF
     */
    public function pdf(TrainingPlan $plan): string
    {
        $assessment = $plan->getAssessment();

        try {
            $response = $this->client->request('POST', '/v1/plan.pdf', [
                'json' => AssessmentPayload::fromAssessment($assessment),
            ]);

            $contentType = $response->getHeaders()['content-type'][0] ?? '';
            $body = $response->getContent();
        } catch (HttpExceptionInterface $exception) {
            throw new PlanServiceUnavailable('The training-plan service did not return a PDF.', previous: $exception);
        }

        // The endpoint answers a flagged assessment with the referral as JSON
        // rather than a document. The controller refuses to ask for a
        // referral's PDF, so getting here means the two sides disagree about
        // the screening - which is a fault, not a document.
        if (!str_starts_with($contentType, 'application/pdf')) {
            throw new PlanServiceUnavailable(sprintf('The training-plan service answered /v1/plan.pdf with "%s" rather than a PDF.', $contentType));
        }

        return $body;
    }

    /**
     * One POST of an assessment, decoded.
     *
     * Symfony's client is lazy, so a refused connection surfaces at getContent
     * rather than at request, and a non-2xx surfaces as an exception when the
     * body is read. Both live inside the same try for that reason.
     *
     * @return array<array-key, mixed>
     *
     * @throws PlanServiceUnavailable
     */
    private function post(string $path, Assessment $assessment): array
    {
        try {
            return $this->client->request('POST', $path, [
                'json' => AssessmentPayload::fromAssessment($assessment),
            ])->toArray();
        } catch (HttpExceptionInterface $exception) {
            throw new PlanServiceUnavailable(sprintf('The training-plan service did not answer %s.', $path), previous: $exception);
        }
    }
}
