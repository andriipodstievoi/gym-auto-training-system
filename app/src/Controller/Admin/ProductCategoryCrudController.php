<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Field\TranslatedField;
use App\Domain\Enum\ProductKind;
use App\Entity\ProductCategory;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * @extends AbstractCrudController<ProductCategory>
 */
final class ProductCategoryCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ProductCategory::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Product category')
            ->setEntityLabelInPlural('Product categories')
            ->setDefaultSort(['position' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TranslatedField::new('name');
        yield TextField::new('slug');
        yield ChoiceField::new('kind')->setChoices(ProductKind::cases());
        yield IntegerField::new('position');
    }
}
