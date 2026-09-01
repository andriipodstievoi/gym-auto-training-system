<?php

declare(strict_types=1);

namespace App\Plan;

use App\Domain\Enum\PlanStatus;

/**
 * The screening gate's answer: a doctor first, not a programme.
 *
 * Mirrors MedicalReferral in ai-service/app/schemas.py. It is a result rather
 * than an exception because nothing went wrong - this is the questionnaire
 * working, and the member is owed a page rather than an error.
 */
final readonly class MedicalReferralResult
{
    /**
     * @param list<string> $redFlags the screening questions answered yes to,
     *                               named as the contract names them
     */
    public function __construct(
        public array $redFlags,
        public string $message = '',
    ) {
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            PayloadReader::strings($payload, 'red_flags'),
            PayloadReader::string($payload['message'] ?? null),
        );
    }

    /**
     * The shape this would have arrived in, so a referral raised locally is
     * stored the same way as one the service sent. A row's payload should not
     * betray which side of the wire noticed the flag.
     *
     * @return array{status: string, red_flags: list<string>, message: string}
     */
    public function toPayload(): array
    {
        return [
            'status' => PlanStatus::MEDICAL_REFERRAL->value,
            'red_flags' => $this->redFlags,
            'message' => $this->message,
        ];
    }
}
