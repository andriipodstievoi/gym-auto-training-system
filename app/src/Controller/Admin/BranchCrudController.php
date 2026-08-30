<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Field\TranslatedField;
use App\Entity\Branch;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * @extends AbstractCrudController<Branch>
 */
final class BranchCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Branch::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Branch')
            ->setEntityLabelInPlural('Branches')
            ->setDefaultSort(['name' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addTab('Details');
        yield TextField::new('name');
        yield SlugField::new('slug')->setTargetFieldName('name')->hideOnIndex();
        yield TranslatedField::new('description')->asTextarea()->hideOnIndex();
        yield BooleanField::new('active');

        yield FormField::addTab('Address');
        yield TextField::new('addressLine');
        yield TextField::new('city')->hideOnIndex();
        yield TextField::new('postalCode')->hideOnIndex();
        yield NumberField::new('latitude')->setNumDecimals(6)->hideOnIndex();
        yield NumberField::new('longitude')->setNumDecimals(6)->hideOnIndex();

        yield FormField::addTab('Contact');
        yield TextField::new('phone');
        yield EmailField::new('email');
    }
}
