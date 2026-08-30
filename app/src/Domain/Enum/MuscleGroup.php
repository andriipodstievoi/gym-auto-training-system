<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * Muscle groups the rule engine budgets weekly set volume against.
 */
enum MuscleGroup: string
{
    case CHEST = 'chest';
    case BACK = 'back';
    case SHOULDERS = 'shoulders';
    case BICEPS = 'biceps';
    case TRICEPS = 'triceps';
    case QUADS = 'quads';
    case HAMSTRINGS = 'hamstrings';
    case GLUTES = 'glutes';
    case CALVES = 'calves';
    case CORE = 'core';

    /**
     * @return list<self> the groups trained by an upper-body session
     */
    public static function upperBody(): array
    {
        return [self::CHEST, self::BACK, self::SHOULDERS, self::BICEPS, self::TRICEPS];
    }

    /**
     * @return list<self> the groups trained by a lower-body session
     */
    public static function lowerBody(): array
    {
        return [self::QUADS, self::HAMSTRINGS, self::GLUTES, self::CALVES];
    }
}
