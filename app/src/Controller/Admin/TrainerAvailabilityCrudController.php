<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\TrainerAvailability;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TimeField;

/**
 * The weekly hours coaches work.
 *
 * Fully editable, unlike bookings: this is a rota, not a record of something
 * that happened, and reception setting a coach's hours for them on their first
 * day is the normal case rather than an abuse.
 *
 * @extends AbstractCrudController<TrainerAvailability>
 */
final class TrainerAvailabilityCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return TrainerAvailability::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Availability window')
            ->setEntityLabelInPlural('Trainer availability')
            ->setDefaultSort(['weekday' => 'ASC', 'startTime' => 'ASC'])
            ->setHelp('index', 'Recurring weekly hours, in the gym\'s own timezone. Booking expands these into hour-long slots.');
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('trainer');
        yield ChoiceField::new('weekday')
            ->setChoices([
                'Monday' => 1,
                'Tuesday' => 2,
                'Wednesday' => 3,
                'Thursday' => 4,
                'Friday' => 5,
                'Saturday' => 6,
                'Sunday' => 7,
            ]);
        yield TimeField::new('startTime');
        yield TimeField::new('endTime')->setHelp('Exclusive: a window ending at 13:00 offers no 13:00 slot.');
        yield BooleanField::new('active');
    }
}
