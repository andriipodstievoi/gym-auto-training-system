<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * Where a shop order stands.
 *
 * PENDING exists for the same reason it does on a membership: checkout is
 * asynchronous. The row is written when the member is handed to Stripe, and
 * only the webhook may promote it to PAID - so an abandoned basket never turns
 * into a fulfilled order. FULFILLED is the one transition staff make by hand,
 * once the parcel has actually left the counter.
 */
enum OrderStatus: string
{
    case PENDING = 'pending';
    case PAID = 'paid';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';
    case FULFILLED = 'fulfilled';

    /**
     * Whether the money for this order actually arrived. Fulfilled counts:
     * a parcel is only handed over after it was paid for.
     */
    public function isSettled(): bool
    {
        return match ($this) {
            self::PAID, self::FULFILLED => true,
            self::PENDING, self::CANCELLED, self::EXPIRED => false,
        };
    }

    public function translationKey(): string
    {
        return 'shop.order.state.'.$this->value;
    }
}
