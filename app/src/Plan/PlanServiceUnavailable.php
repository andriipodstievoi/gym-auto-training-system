<?php

declare(strict_types=1);

namespace App\Plan;

use RuntimeException;

/**
 * Thrown when the training-plan service cannot be reached, times out, or
 * answers with something that is not a plan.
 *
 * The service being down is an expected state, not a broken one: it is a
 * second runtime with its own deployment, it is not running in CI, and a fresh
 * clone starts without it. So this exists to be caught and turned into an
 * honest message, exactly as PaymentsNotConfigured is - never to reach a
 * member as a 500.
 *
 * @see \App\Payment\PaymentsNotConfigured the same bargain, for Stripe
 */
final class PlanServiceUnavailable extends RuntimeException
{
}
