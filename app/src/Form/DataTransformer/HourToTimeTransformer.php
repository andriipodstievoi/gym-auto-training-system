<?php

declare(strict_types=1);

namespace App\Form\DataTransformer;

use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * Maps a whole hour on the form to the time column behind it.
 *
 * A coach's working window only ever starts and ends on the hour, because
 * slots are whole hours - offering :37 in a picker that can never produce an
 * 09:37 slot would be a lie. Symfony's TimeType can be told that with
 * with_minutes: false, but it still renders a compound widget whose label
 * points at the wrapping div rather than the select inside it, which Lighthouse
 * correctly reports as a select with no label. Transforming to a plain
 * ChoiceType gives one control with one real label instead.
 *
 * @implements DataTransformerInterface<DateTimeImmutable, string>
 */
final class HourToTimeTransformer implements DataTransformerInterface
{
    /**
     * The date half is irrelevant - the column is a TIME - but it has to be
     * something, and a fixed epoch keeps two equal hours comparing equal.
     */
    private const string EPOCH = '1970-01-01';

    public function transform(mixed $value): string
    {
        if (null === $value) {
            return '';
        }

        return $value->format('H');
    }

    public function reverseTransform(mixed $value): ?DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }

        $time = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            self::EPOCH.' '.$value.':00:00',
            new DateTimeZone('UTC'),
        );

        if (false === $time) {
            throw new TransformationFailedException(sprintf('"%s" is not an hour.', $value));
        }

        return $time;
    }
}
