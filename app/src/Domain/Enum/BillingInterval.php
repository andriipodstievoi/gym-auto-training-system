<?php

declare(strict_types=1);

namespace App\Domain\Enum;

enum BillingInterval: string
{
    case MONTHLY = 'monthly';
    case YEARLY = 'yearly';

    public function months(): int
    {
        return match ($this) {
            self::MONTHLY => 1,
            self::YEARLY => 12,
        };
    }
}
