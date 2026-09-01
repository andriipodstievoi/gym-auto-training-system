<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * Where a personal-training session stands.
 *
 * A booking starts as REQUESTED because the coach, not the member, owns the
 * diary: a member asking for an hour is not the same as a coach agreeing to
 * work it. Only the coach may CONFIRM or DECLINE; either party may CANCEL a
 * session that has not happened yet. COMPLETED is the archive state for an
 * hour that was confirmed and has since passed.
 */
enum BookingStatus: string
{
    case REQUESTED = 'requested';
    case CONFIRMED = 'confirmed';
    case DECLINED = 'declined';
    case CANCELLED = 'cancelled';
    case COMPLETED = 'completed';

    /**
     * Whether this booking still occupies its slot. Declined and cancelled
     * hours go back on sale; requested ones are held while the coach decides,
     * so two members cannot both be waiting on the same slot.
     */
    public function holdsSlot(): bool
    {
        return match ($this) {
            self::REQUESTED, self::CONFIRMED => true,
            self::DECLINED, self::CANCELLED, self::COMPLETED => false,
        };
    }

    /**
     * Whether the coach still owes an answer.
     */
    public function awaitsResponse(): bool
    {
        return self::REQUESTED === $this;
    }

    public function translationKey(): string
    {
        return 'booking.status.'.$this->value;
    }
}
