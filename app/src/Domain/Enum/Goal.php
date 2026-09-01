<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * What a member says they are training for. Mirrors the Goal enum in
 * ai-service/app/schemas.py - the two must stay in step.
 *
 * The goal is the single biggest input to the programme: it picks the rep
 * ranges, the intensity targets and how much of the week goes to conditioning.
 *
 * @see \App\Tests\Domain\PythonContractTest which fails if these drift apart.
 */
enum Goal: string
{
    case FAT_LOSS = 'fat_loss';
    case MUSCLE_GAIN = 'muscle_gain';
    case STRENGTH = 'strength';
    case GENERAL_FITNESS = 'general_fitness';

    public function translationKey(): string
    {
        return 'assessment.goal.'.$this->value;
    }
}
