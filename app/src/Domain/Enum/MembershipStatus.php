<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * Where a member's own membership stands.
 *
 * PENDING exists because checkout is asynchronous. The row is written when the
 * member is handed to Stripe, and only the webhook may promote it to ACTIVE -
 * so a member who abandons the Stripe page never ends up with access.
 */
enum MembershipStatus: string
{
    case PENDING = 'pending';
    case ACTIVE = 'active';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';

    /**
     * Whether this status still lets a member through the door. A cancelled
     * membership does: cancelling stops the renewal, it does not refund the
     * period already paid for.
     */
    public function grantsAccess(): bool
    {
        return match ($this) {
            self::ACTIVE, self::CANCELLED => true,
            self::PENDING, self::EXPIRED => false,
        };
    }

    public function translationKey(): string
    {
        return 'account.status.'.$this->value;
    }
}
