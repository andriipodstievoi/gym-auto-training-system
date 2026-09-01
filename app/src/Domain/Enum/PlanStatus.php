<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * What came back when we asked for a programme. Mirrors the PlanStatus enum in
 * ai-service/app/schemas.py - the two must stay in step.
 *
 * There are only two answers, and the second one is not a failure: a member
 * whose PAR-Q+ raises a flag gets a referral instead of a plan, and that
 * referral is stored exactly like a programme is, because it is the answer to
 * the same question and the member has to be able to come back to it.
 *
 * @see \App\Tests\Domain\PythonContractTest which fails if these drift apart.
 */
enum PlanStatus: string
{
    case OK = 'ok';
    case MEDICAL_REFERRAL = 'medical_referral';
}
