<?php

declare(strict_types=1);

namespace App\Payment;

use RuntimeException;

/**
 * Thrown when something asks Stripe to do work while the keys are empty.
 *
 * Running without keys is a supported state, not a broken one - it is how the
 * site runs on a fresh clone and in CI - so this exists to be caught and
 * turned into an honest message, never to reach a user as a 500.
 */
final class PaymentsNotConfigured extends RuntimeException
{
}
