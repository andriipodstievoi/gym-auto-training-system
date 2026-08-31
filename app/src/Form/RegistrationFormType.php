<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Sign-up. The password is unmapped on purpose - the entity only ever holds a
 * hash, and the plain value is handed straight to the hasher by the controller.
 *
 * @extends AbstractType<User>
 */
final class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, [
                'label' => 'register.first_name',
                'attr' => ['autocomplete' => 'given-name', 'autofocus' => true],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'register.last_name',
                'attr' => ['autocomplete' => 'family-name'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'register.email',
                'attr' => ['autocomplete' => 'email'],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'invalid_message' => 'register.error.password_mismatch',
                'first_options' => [
                    'label' => 'register.password',
                    'attr' => ['autocomplete' => 'new-password'],
                    'help' => 'register.password_help',
                ],
                'second_options' => [
                    'label' => 'register.password_repeat',
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'constraints' => [
                    new Assert\NotBlank(message: 'register.error.password_blank'),
                    new Assert\Length(
                        min: 8,
                        // Not a policy, a denial-of-service guard: bcrypt hashes
                        // whatever it is given, however long.
                        max: 4096,
                        minMessage: 'register.error.password_short',
                    ),
                    new Assert\PasswordStrength(
                        minScore: Assert\PasswordStrength::STRENGTH_WEAK,
                        message: 'register.error.password_weak',
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'translation_domain' => 'messages',
        ]);
    }
}
