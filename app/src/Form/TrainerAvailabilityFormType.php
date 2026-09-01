<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\TrainerAvailability;
use App\Form\DataTransformer\HourToTimeTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One weekly window, as a coach types it.
 *
 * The trainer is not a field: a coach may only ever add hours to their own
 * diary, and the controller sets that from the current login rather than
 * trusting a select box.
 *
 * @extends AbstractType<TrainerAvailability>
 */
final class TrainerAvailabilityFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('weekday', ChoiceType::class, [
                'label' => 'coach.availability.weekday',
                'choices' => [
                    'weekday.1' => 1,
                    'weekday.2' => 2,
                    'weekday.3' => 3,
                    'weekday.4' => 4,
                    'weekday.5' => 5,
                    'weekday.6' => 6,
                    'weekday.7' => 7,
                ],
            ])
            // Hours, not minutes: slots are whole hours, and offering :37 in a
            // picker that can never produce a 09:37 slot is a lie.
            //
            // A plain ChoiceType rather than TimeType with with_minutes: false,
            // because TimeType stays a compound widget even with one dropdown
            // in it - its label points at the wrapping div, leaving the select
            // itself unlabelled. See HourToTimeTransformer.
            ->add('startTime', ChoiceType::class, [
                'label' => 'coach.availability.from',
                'choices' => self::hours(),
                'choice_translation_domain' => false,
                'placeholder' => false,
            ])
            ->add('endTime', ChoiceType::class, [
                'label' => 'coach.availability.to',
                'choices' => self::hours(),
                'choice_translation_domain' => false,
                'placeholder' => false,
            ]);

        $builder->get('startTime')->addModelTransformer(new HourToTimeTransformer());
        $builder->get('endTime')->addModelTransformer(new HourToTimeTransformer());
    }

    /**
     * Every hour of the day, labelled the way a coach would write it.
     *
     * @return array<string, string>
     */
    private static function hours(): array
    {
        $hours = [];

        for ($hour = 0; $hour <= 23; ++$hour) {
            $padded = str_pad((string) $hour, 2, '0', STR_PAD_LEFT);
            $hours[$padded.':00'] = $padded;
        }

        return $hours;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TrainerAvailability::class,
            'translation_domain' => 'messages',
        ]);
    }
}
