<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Accounts, including which of them are staff.
 *
 * The password box is unmapped and write-only: the stored hash is never shown,
 * an empty box leaves it alone, and anything typed is hashed on submit rather
 * than landing in the entity as plain text.
 *
 * @extends AbstractCrudController<User>
 */
final class UserCrudController extends AbstractCrudController
{
    public function __construct(private readonly UserPasswordHasherInterface $passwordHasher)
    {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('User')
            ->setEntityLabelInPlural('Users')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['email', 'firstName', 'lastName']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield EmailField::new('email');
        yield TextField::new('firstName');
        yield TextField::new('lastName');
        yield ChoiceField::new('roles')
            ->setChoices(['Administrator' => 'ROLE_ADMIN'])
            ->allowMultipleChoices()
            ->setHelp('Everyone has ROLE_USER. Tick this to add back-office access.');
        yield ChoiceField::new('locale')
            ->setChoices(['English' => 'en', 'Latviešu' => 'lv', 'Русский' => 'ru'])
            ->setHelp('The language this member is written to in.');
        yield TextField::new('plainPassword')
            ->setFormType(PasswordType::class)
            ->setFormTypeOption('mapped', false)
            ->setFormTypeOption('required', Crud::PAGE_NEW === $pageName)
            ->setHelp('Leave empty to keep the current password.')
            ->onlyOnForms();
        yield DateTimeField::new('createdAt')->onlyOnIndex();
    }

    /**
     * @return FormBuilderInterface<mixed>
     */
    public function createEditFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        return $this->hashPasswordOnSubmit(parent::createEditFormBuilder($entityDto, $formOptions, $context));
    }

    /**
     * @return FormBuilderInterface<mixed>
     */
    public function createNewFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        return $this->hashPasswordOnSubmit(parent::createNewFormBuilder($entityDto, $formOptions, $context));
    }

    /**
     * @param FormBuilderInterface<mixed> $formBuilder
     *
     * @return FormBuilderInterface<mixed>
     */
    private function hashPasswordOnSubmit(FormBuilderInterface $formBuilder): FormBuilderInterface
    {
        return $formBuilder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();

            if (!$form->has('plainPassword')) {
                return;
            }

            $plainPassword = $form->get('plainPassword')->getData();
            $user = $form->getData();

            if ($user instanceof User && is_string($plainPassword) && '' !== $plainPassword) {
                $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
            }
        });
    }
}
