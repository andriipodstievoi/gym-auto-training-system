<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Field\TranslatedField;
use App\Domain\Enum\EquipmentType;
use App\Domain\Enum\Limitation;
use App\Domain\Enum\MovementPattern;
use App\Domain\Enum\MuscleGroup;
use App\Entity\Exercise;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * @extends AbstractCrudController<Exercise>
 */
final class ExerciseCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Exercise::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Exercise')
            ->setEntityLabelInPlural('Exercise library')
            ->setDefaultSort(['primaryMuscle' => 'ASC', 'slug' => 'ASC'])
            ->setPaginatorPageSize(50);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TranslatedField::new('name');
        yield TextField::new('slug')->hideOnIndex();
        yield ChoiceField::new('primaryMuscle')->setChoices(MuscleGroup::cases());
        yield ChoiceField::new('pattern')->setChoices(MovementPattern::cases());
        yield ChoiceField::new('equipment')->setChoices(EquipmentType::cases());
        yield ChoiceField::new('secondaryMuscles')
            ->setChoices(self::choicesFrom(MuscleGroup::cases()))
            ->allowMultipleChoices()
            ->hideOnIndex();
        yield ChoiceField::new('contraindications')
            ->setChoices(self::choicesFrom(Limitation::cases()))
            ->allowMultipleChoices()
            ->setHelp('A member declaring one of these never gets this movement.');
        yield IntegerField::new('difficulty');
        yield TranslatedField::new('instructions')->asTextarea()->hideOnIndex();
        yield BooleanField::new('active');
    }

    /**
     * @param array<MuscleGroup|Limitation> $cases
     *
     * @return array<string, string>
     */
    private static function choicesFrom(array $cases): array
    {
        $choices = [];
        foreach ($cases as $case) {
            $choices[ucfirst(str_replace('_', ' ', $case->value))] = $case->value;
        }

        return $choices;
    }
}
