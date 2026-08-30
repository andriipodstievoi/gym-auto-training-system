<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Field\TranslatedField;
use App\Domain\Enum\BillingInterval;
use App\Entity\MembershipPlan;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;

/**
 * @extends AbstractCrudController<MembershipPlan>
 */
final class MembershipPlanCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return MembershipPlan::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Membership plan')
            ->setEntityLabelInPlural('Membership plans')
            ->setDefaultSort(['position' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TranslatedField::new('name');
        yield SlugField::new('slug')->setTargetFieldName('slug')->hideOnIndex();
        yield MoneyField::new('priceCents')->setCurrency('EUR')->setStoredAsCents();
        yield ChoiceField::new('billingInterval')->setChoices(BillingInterval::cases());
        yield TranslatedField::new('description')->asTextarea()->hideOnIndex();
        yield ArrayField::new('features')
            ->setHelp('One entry per bullet. Each entry is a locale map such as en, lv, ru.')
            ->hideOnIndex();
        yield BooleanField::new('allBranches');
        yield BooleanField::new('active');
        yield IntegerField::new('position')->hideOnIndex();
    }
}
