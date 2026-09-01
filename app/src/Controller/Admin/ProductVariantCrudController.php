<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Field\TranslatedField;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Repository\ProductRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use RuntimeException;

/**
 * Flavours, sizes and widths.
 *
 * A variant carries an absolute price rather than a delta, so this is also
 * where a single size gets repriced without dragging the rest along.
 *
 * @extends AbstractCrudController<ProductVariant>
 */
final class ProductVariantCrudController extends AbstractCrudController
{
    public function __construct(private readonly ProductRepository $products)
    {
    }

    public static function getEntityFqcn(): string
    {
        return ProductVariant::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Product variant')
            ->setEntityLabelInPlural('Product variants')
            ->setDefaultSort(['product' => 'ASC', 'position' => 'ASC']);
    }

    /**
     * A variant cannot exist without a product, so the constructor demands
     * one and this hands it the first one on the shelf. It has to be a managed
     * row rather than a blank object: the product picker is an entity choice
     * list, and an unsaved entity has no id for it to select by. The form
     * replaces it with whatever the admin actually picks.
     */
    public function createEntity(string $entityFqcn): ProductVariant
    {
        $product = $this->products->findOneBy([], ['slug' => 'ASC']);

        if (!$product instanceof Product) {
            throw new RuntimeException('Add a product before adding variants; a size with nothing to be a size of is not a thing.');
        }

        return new ProductVariant($product);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('product')->setFormTypeOption('placeholder', false);
        yield TranslatedField::new('label');
        yield TextField::new('sku');
        yield MoneyField::new('priceCents')->setCurrency('EUR')->setStoredAsCents();
        yield IntegerField::new('stock');
        yield IntegerField::new('position');
        yield BooleanField::new('active');
    }
}
