<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * What a floor zone is for.
 *
 * A branch page draws both kinds the same way and both are clickable, but a
 * training floor holds machines a member trains on, while an amenity room
 * holds fixtures they merely use. The distinction drives how the plan is
 * coloured and how the detail panel is worded.
 */
enum ZoneKind: string
{
    case TRAINING = 'training';
    case AMENITY = 'amenity';
}
