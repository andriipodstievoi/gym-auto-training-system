<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * How long a member has been training consistently. Mirrors the Experience
 * enum in ai-service/app/schemas.py - the two must stay in step.
 *
 * The bands are the ones the engine reasons in: under six months, six months
 * to two years, and beyond. Training age drives starting volume and how fast
 * the mesocycle is allowed to add work.
 *
 * @see \App\Tests\Domain\PythonContractTest which fails if these drift apart.
 */
enum Experience: string
{
    case BEGINNER = 'beginner';
    case INTERMEDIATE = 'intermediate';
    case ADVANCED = 'advanced';

    public function translationKey(): string
    {
        return 'assessment.experience.'.$this->value;
    }
}
