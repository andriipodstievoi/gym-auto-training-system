<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * Joint limitations a member can declare. Mirrors the Limitation enum in
 * ai-service/app/schemas.py - the two must stay in step.
 */
enum Limitation: string
{
    case SHOULDER = 'shoulder';
    case LOWER_BACK = 'lower_back';
    case KNEE = 'knee';
    case ELBOW = 'elbow';
    case HIP = 'hip';
    case NECK = 'neck';
}
