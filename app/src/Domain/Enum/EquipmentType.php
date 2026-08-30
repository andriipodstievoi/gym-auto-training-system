<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * What a member needs to perform an exercise. Drives filtering when somebody
 * answers the assessment with "home_basic" or "bodyweight".
 */
enum EquipmentType: string
{
    case BARBELL = 'barbell';
    case DUMBBELL = 'dumbbell';
    case MACHINE = 'machine';
    case CABLE = 'cable';
    case KETTLEBELL = 'kettlebell';
    case BAND = 'band';
    case BODYWEIGHT = 'bodyweight';
    case CARDIO = 'cardio';

    /**
     * @return list<self> what is realistically available in a home setup
     */
    public static function homeBasic(): array
    {
        return [self::DUMBBELL, self::KETTLEBELL, self::BAND, self::BODYWEIGHT];
    }
}
