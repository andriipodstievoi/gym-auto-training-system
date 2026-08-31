<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Domain\Enum\MembershipStatus;
use App\Entity\UserMembership;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Memberships people hold.
 *
 * No NEW action: a membership is the record of a payment, and the only things
 * that may create one are checkout and the webhook that confirms it. Staff can
 * look, and can correct a status - they cannot invent a purchase.
 *
 * @extends AbstractCrudController<UserMembership>
 */
final class UserMembershipCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return UserMembership::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Membership')
            ->setEntityLabelInPlural('Memberships')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->remove(Crud::PAGE_INDEX, Action::NEW)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('user');
        yield AssociationField::new('plan');
        yield ChoiceField::new('status')->setChoices(MembershipStatus::cases());
        yield MoneyField::new('pricePaidCents')
            ->setCurrency('EUR')
            ->setStoredAsCents()
            ->setLabel('Paid')
            ->setFormTypeOption('disabled', true)
            ->setHelp('What this member was charged. Repricing the plan must not change it.');
        yield DateTimeField::new('startsAt');
        yield DateTimeField::new('endsAt');
        yield DateTimeField::new('createdAt')->hideOnForm();
        yield TextField::new('stripeCheckoutSessionId')
            ->setLabel('Stripe session')
            ->setFormTypeOption('disabled', true)
            ->onlyOnDetail();
        yield TextField::new('stripePaymentIntentId')
            ->setLabel('Stripe payment intent')
            ->setFormTypeOption('disabled', true)
            ->onlyOnDetail();
    }
}
