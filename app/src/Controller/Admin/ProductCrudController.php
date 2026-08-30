<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Field\TranslatedField;
use App\Entity\Product;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * @extends AbstractCrudController<Product>
 */
final class ProductCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Product::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Product')
            ->setEntityLabelInPlural('Products')
            ->setDefaultSort(['slug' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TranslatedField::new('name');
        yield TextField::new('slug')->hideOnIndex();
        yield TextField::new('sku');
        yield AssociationField::new('category');
        yield MoneyField::new('priceCents')->setCurrency('EUR')->setStoredAsCents();
        yield IntegerField::new('stock');
        yield TranslatedField::new('description')->asTextarea()->hideOnIndex();
        yield TextField::new('imagePath')->hideOnIndex();
        yield BooleanField::new('active');
    }
}
