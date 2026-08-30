<?php

declare(strict_types=1);

namespace App\Admin\Field;

use App\Domain\TranslatedString;
use App\Form\TranslatedStringType;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\FieldTrait;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * EasyAdmin field for a {@see TranslatedString} property.
 *
 * Lists and detail pages show the value resolved through the normal fallback
 * chain; forms expose one input per locale.
 */
final class TranslatedField implements FieldInterface
{
    use FieldTrait;

    public static function new(string $propertyName, TranslatableInterface|string|bool|null $label = null): self
    {
        return (new self())
            ->setProperty($propertyName)
            ->setLabel($label)
            ->setTemplatePath('@EasyAdmin/crud/field/text.html.twig')
            ->setFormType(TranslatedStringType::class)
            ->formatValue(static fn (mixed $value): string => $value instanceof TranslatedString ? $value->get() : '');
    }

    /**
     * Render the field as textareas rather than single-line inputs.
     */
    public function asTextarea(): self
    {
        return $this->setFormTypeOption('multiline', true);
    }
}
