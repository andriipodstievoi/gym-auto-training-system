<?php

declare(strict_types=1);

namespace App\Domain\Enum;

enum TrainerSpeciality: string
{
    case STRENGTH = 'strength';
    case HYPERTROPHY = 'hypertrophy';
    case WEIGHT_LOSS = 'weight_loss';
    case REHAB = 'rehab';
    case CONDITIONING = 'conditioning';
    case POWERLIFTING = 'powerlifting';
}
