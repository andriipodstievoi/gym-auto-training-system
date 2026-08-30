<?php

declare(strict_types=1);

namespace App\Form;

use App\Domain\TranslatedString;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Edits a {@see TranslatedString} as one input per locale.
 *
 * The model transformer is what lets the back office work with a plain
 * locale-keyed array while the entity keeps its value object.
 *
 * @extends AbstractType<TranslatedString>
 */
final class TranslatedStringType extends AbstractType
{
    private const array LOCALE_LABELS = [
        'en' => 'English',
        'lv' => 'Latviešu',
        'ru' => 'Русский',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (TranslatedString::LOCALES as $locale) {
            $builder->add($locale, $options['multiline'] ? TextareaType::class : TextType::class, [
                'label' => self::LOCALE_LABELS[$locale],
                'required' => $options['required'] && 'en' === $locale,
                'attr' => $options['multiline'] ? ['rows' => 3] : [],
            ]);
        }

        $builder->addModelTransformer(new CallbackTransformer(
            static fn (?TranslatedString $value): array => $value?->toArray() ?? [],
            static fn (?array $value): TranslatedString => new TranslatedString($value ?? []),
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // The transformer hands the children a plain array, not an object.
            'data_class' => null,
            'multiline' => false,
            'empty_data' => [],
            'error_bubbling' => false,
        ]);

        $resolver->setAllowedTypes('multiline', 'bool');
    }
}
