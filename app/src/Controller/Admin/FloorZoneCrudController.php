<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Field\TranslatedField;
use App\Entity\FloorZone;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * @extends AbstractCrudController<FloorZone>
 */
final class FloorZoneCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return FloorZone::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Floor zone')
            ->setEntityLabelInPlural('Floor zones')
            ->setDefaultSort(['branch' => 'ASC', 'position' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('branch');
        yield TranslatedField::new('name');
        yield TextField::new('svgId')
            ->setHelp('Must match the id of the matching shape in the floor-plan SVG.');
        yield TranslatedField::new('description')->asTextarea()->hideOnIndex();
        yield IntegerField::new('position');
    }
}
