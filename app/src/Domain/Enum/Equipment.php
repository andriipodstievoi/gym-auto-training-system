<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * What a member actually has to train with. Mirrors the Equipment enum in
 * ai-service/app/schemas.py - the two must stay in step.
 *
 * Deliberately three tiers rather than a checklist of machines. A programme
 * that asks for a hack squat somebody does not own is worthless, and a
 * questionnaire that makes them tick forty boxes never gets finished.
 *
 * Not to be confused with App\Entity\Equipment, which is a specific machine on
 * a specific gym floor. This is the tier a member trains at.
 *
 * @see \App\Tests\Domain\PythonContractTest which fails if these drift apart.
 */
enum Equipment: string
{
    case FULL_GYM = 'full_gym';
    case HOME_BASIC = 'home_basic';
    case BODYWEIGHT = 'bodyweight';

    public function translationKey(): string
    {
        return 'assessment.equipment.'.$this->value;
    }
}
