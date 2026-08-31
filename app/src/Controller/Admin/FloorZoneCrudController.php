<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Field\TranslatedField;
use App\Domain\Enum\ZoneKind;
use App\Entity\FloorZone;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
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
            ->setDefaultSort(['branch' => 'ASC', 'floor' => 'ASC', 'position' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('branch');
        yield TranslatedField::new('name');
        yield TextField::new('svgId')
            ->setHelp('Identifies the room on the floor plan. Lowercase letters, digits and hyphens.');
        yield ChoiceField::new('kind')
            ->setChoices(ZoneKind::cases())
            ->setHelp('Training floors hold machines; amenity rooms hold fittings such as lockers or a sauna.');
        yield IntegerField::new('floor')
            ->setHelp('0 is the ground floor. The plan draws one storey at a time.');
        yield TranslatedField::new('description')->asTextarea()->hideOnIndex();
        yield IntegerField::new('position')
            ->setHelp('Order within the storey, which is the order rooms are laid out in.');
    }
}
