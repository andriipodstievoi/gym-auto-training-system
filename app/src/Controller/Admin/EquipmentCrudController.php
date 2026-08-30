<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Field\TranslatedField;
use App\Domain\Enum\EquipmentType;
use App\Entity\Equipment;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;

/**
 * @extends AbstractCrudController<Equipment>
 */
final class EquipmentCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Equipment::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Equipment item')
            ->setEntityLabelInPlural('Equipment');
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('zone');
        yield TranslatedField::new('name');
        yield ChoiceField::new('type')->setChoices(EquipmentType::cases());
        yield IntegerField::new('quantity');
    }
}
