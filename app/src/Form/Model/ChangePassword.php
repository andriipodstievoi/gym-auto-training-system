<?php

declare(strict_types=1);

namespace App\Form\Model;

use Symfony\Component\Security\Core\Validator\Constraints as SecurityAssert;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Carries a password change between the form and the hasher.
 *
 * Neither value belongs on {@see \App\Entity\User}, which only ever holds a
 * hash, so they live here and are discarded when the request ends.
 */
final class ChangePassword
{
    #[SecurityAssert\UserPassword(message: 'account.password.error.current_wrong')]
    public string $currentPassword = '';

    #[Assert\NotBlank(message: 'register.error.password_blank')]
    #[Assert\Length(min: 8, max: 4096, minMessage: 'register.error.password_short')]
    #[Assert\PasswordStrength(
        minScore: Assert\PasswordStrength::STRENGTH_WEAK,
        message: 'register.error.password_weak',
    )]
    public string $newPassword = '';
}
