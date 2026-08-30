<?php

declare(strict_types=1);

namespace App\Domain\Enum;

enum ProductKind: string
{
    case APPAREL = 'apparel';
    case SUPPLEMENT = 'supplement';
    case ACCESSORY = 'accessory';
}
