<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Field\TranslatedField;
use App\Domain\Enum\TrainerSpeciality;
use App\Entity\Trainer;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * @extends AbstractCrudController<Trainer>
 */
final class TrainerCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Trainer::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Trainer')
            ->setEntityLabelInPlural('Trainers')
            ->setDefaultSort(['fullName' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('fullName');
        yield SlugField::new('slug')->setTargetFieldName('fullName')->hideOnIndex();
        yield AssociationField::new('branch');
        yield AssociationField::new('user')
            ->setHelp('Optional. Links this coach to a login, which they need from M5 onwards.')
            ->hideOnIndex();
        yield ChoiceField::new('specialities')
            ->setChoices(self::specialityChoices())
            ->allowMultipleChoices();
        yield ChoiceField::new('languages')
            ->setChoices(['English' => 'en', 'Latviešu' => 'lv', 'Русский' => 'ru'])
            ->allowMultipleChoices();
        yield MoneyField::new('hourlyRateCents')->setCurrency('EUR')->setStoredAsCents();
        yield TranslatedField::new('bio')->asTextarea()->hideOnIndex();
        yield TextField::new('photoPath')->hideOnIndex();
        yield BooleanField::new('active');
    }

    /**
     * @return array<string, string>
     */
    private static function specialityChoices(): array
    {
        $choices = [];
        foreach (TrainerSpeciality::cases() as $case) {
            $choices[ucfirst(str_replace('_', ' ', $case->value))] = $case->value;
        }

        return $choices;
    }
}
