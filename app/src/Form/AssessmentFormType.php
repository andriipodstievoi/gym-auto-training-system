<?php

declare(strict_types=1);

namespace App\Form;

use App\Domain\Enum\Equipment;
use App\Domain\Enum\Experience;
use App\Domain\Enum\Goal;
use App\Domain\Enum\Limitation;
use App\Entity\Assessment;
use App\Form\DataTransformer\LinesToListTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The questionnaire, as a member fills it in.
 *
 * A form type rather than a hand-written form, unlike the booking and cart
 * forms next door, because this one has eighteen fields over five steps and
 * three things a hand-written form would have to re-implement badly: the 422
 * on an invalid submission, the per-field error next to the field that caused
 * it, and - the one that matters most here - repopulating every answer when
 * the page comes back. A member who typed eighteen answers and got a plan
 * service that was not answering must not be handed an empty form.
 *
 * CSRF is deliberately switched off on the form and checked by hand in the
 * controller instead, under the token id "assessment", so the questionnaire
 * carries its own token like every other member-facing POST here rather than
 * sharing the global form one.
 *
 * Every choice is rendered as radios or checkboxes rather than as a select.
 * Two milestones have now lost a Lighthouse run to a select whose label points
 * at a wrapper instead of at the control; a fieldset of individually labelled
 * radios cannot have that defect.
 *
 * @see TrainerAvailabilityFormType for the same lesson, from the
 *      other direction
 *
 * @extends AbstractType<Assessment>
 */
final class AssessmentFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Step 1 - about you.
            //
            // Every property behind this form is a non-nullable scalar, so a
            // POST that simply omits a key would otherwise reach the setter as
            // null and 500. empty_data answers that: the numbers fall back to
            // a zero the Range constraints then reject with a 422, and the
            // choices fall back to the default the entity already declares.
            //
            // They also start blank rather than at that zero: a questionnaire
            // that opens with "0" in the age box reads as already answered,
            // and nobody's real answer is ever a zero. data => null only
            // decides what an unanswered field shows - a submitted value comes
            // back on the page as the member typed it.
            ->add('age', IntegerType::class, [
                'label' => 'assessment.field.age',
                'data' => null,
                'empty_data' => '0',
                'attr' => ['min' => 14, 'max' => 90, 'inputmode' => 'numeric', 'autocomplete' => 'off'],
            ])
            ->add('heightCm', IntegerType::class, [
                'label' => 'assessment.field.height_cm',
                'data' => null,
                'empty_data' => '0',
                'attr' => ['min' => 120, 'max' => 230, 'inputmode' => 'numeric', 'autocomplete' => 'off'],
            ])
            ->add('weightKg', NumberType::class, [
                'label' => 'assessment.field.weight_kg',
                'data' => null,
                'empty_data' => '0',
                'html5' => true,
                'scale' => 1,
                'attr' => ['min' => 35, 'max' => 250, 'step' => '0.1', 'inputmode' => 'decimal', 'autocomplete' => 'off'],
            ])

            // Step 2 - what you are training for.
            ->add('goal', EnumType::class, [
                'label' => 'assessment.field.goal',
                'class' => Goal::class,
                'choice_label' => static fn (Goal $case): string => $case->translationKey(),
                'expanded' => true,
                'empty_data' => Goal::GENERAL_FITNESS->value,
            ])
            ->add('experience', EnumType::class, [
                'label' => 'assessment.field.experience',
                'class' => Experience::class,
                'choice_label' => static fn (Experience $case): string => $case->translationKey(),
                'expanded' => true,
                'empty_data' => Experience::BEGINNER->value,
            ])

            // Step 3 - your week. Radios rather than a number field: the
            // engine only accepts two to six days and thirty to a hundred and
            // twenty minutes, and a control that cannot express a wrong answer
            // beats one that validates it afterwards.
            ->add('daysPerWeek', ChoiceType::class, [
                'label' => 'assessment.field.days_per_week',
                'choices' => array_combine(range(2, 6), range(2, 6)),
                'choice_translation_domain' => false,
                'expanded' => true,
                'empty_data' => '3',
            ])
            ->add('minutesPerSession', ChoiceType::class, [
                'label' => 'assessment.field.minutes_per_session',
                'choices' => array_combine([30, 45, 60, 75, 90, 120], [30, 45, 60, 75, 90, 120]),
                'choice_translation_domain' => false,
                'expanded' => true,
                'empty_data' => '60',
            ])

            // Step 4 - what you train with.
            ->add('equipment', EnumType::class, [
                'label' => 'assessment.field.equipment',
                'class' => Equipment::class,
                'choice_label' => static fn (Equipment $case): string => $case->translationKey(),
                'expanded' => true,
                'empty_data' => Equipment::FULL_GYM->value,
            ])
            // Stored as backing values rather than as enum instances, like
            // Trainer::$specialities, so the column survives a member of the
            // enum being retired.
            ->add('limitations', ChoiceType::class, [
                'label' => 'assessment.field.limitations',
                'help' => 'assessment.field.limitations_help',
                'choices' => self::limitationChoices(),
                'expanded' => true,
                'multiple' => true,
                'required' => false,
            ])
            ->add('dislikedExercises', TextareaType::class, [
                'label' => 'assessment.field.disliked',
                'help' => 'assessment.field.disliked_help',
                'required' => false,
                'attr' => ['rows' => 3],
            ]);

        $builder->get('dislikedExercises')->addModelTransformer(new LinesToListTransformer());

        // Step 5 - the screening.
        //
        // Named by the contract rather than by the property: the field name,
        // the POST key and the translation key are then the same string as the
        // key Pydantic reads, and PAR_Q_FIELDS is the only place that says
        // what the eight questions are called.
        //
        // An unticked box is a "no", which is what both sides of the wire
        // already default these to - ParQ in schemas.py and the entity here.
        foreach (Assessment::PAR_Q_FIELDS as $field) {
            $builder->add($field, CheckboxType::class, [
                'label' => 'assessment.parq.'.$field,
                'property_path' => self::propertyFor($field),
                'required' => false,
            ]);
        }
    }

    /**
     * The joint limitations, labelled.
     *
     * Built here rather than read off a translationKey() helper because
     * Limitation is shared with the exercise library and carries no view
     * concerns; the other three enums are questionnaire-only and do have one.
     *
     * @return array<string, string>
     */
    private static function limitationChoices(): array
    {
        $choices = [];

        foreach (Limitation::cases() as $case) {
            $choices['assessment.limitation.'.$case->value] = $case->value;
        }

        return $choices;
    }

    /**
     * heart_condition -> heartCondition.
     */
    private static function propertyFor(string $field): string
    {
        return lcfirst(str_replace('_', '', ucwords($field, '_')));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Assessment::class,
            'translation_domain' => 'messages',
            // Checked by hand in AssessmentController under the id
            // "assessment", the way every other member-facing POST here does.
            'csrf_protection' => false,
        ]);
    }

    /**
     * Shortens every POST key from assessment_form[...] to assessment[...],
     * which is also the token id.
     */
    public function getBlockPrefix(): string
    {
        return 'assessment';
    }
}
