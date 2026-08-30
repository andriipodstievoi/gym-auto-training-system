<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * Movement patterns, used to balance a session rather than stack six
 * variations of the same push.
 */
enum MovementPattern: string
{
    case HORIZONTAL_PUSH = 'horizontal_push';
    case VERTICAL_PUSH = 'vertical_push';
    case HORIZONTAL_PULL = 'horizontal_pull';
    case VERTICAL_PULL = 'vertical_pull';
    case SQUAT = 'squat';
    case HINGE = 'hinge';
    case LUNGE = 'lunge';
    case CARRY = 'carry';
    case ISOLATION = 'isolation';
    case CONDITIONING = 'conditioning';

    public function isCompound(): bool
    {
        return !in_array($this, [self::ISOLATION, self::CONDITIONING], true);
    }
}
