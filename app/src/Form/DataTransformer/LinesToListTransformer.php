<?php

declare(strict_types=1);

namespace App\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;

/**
 * Maps a textarea onto the JSON list behind it, one entry per line.
 *
 * Members name the movements they would rather not be given in their own
 * words - "burpees", "anything overhead" - so the column is a list of strings
 * rather than a set of ids, and a textarea is the only control that lets
 * somebody write three of them without a JavaScript widget.
 *
 * Blank lines are dropped and both the number of entries and their length are
 * capped here rather than by a constraint: this is the only place that knows
 * one textarea is really a list, and an unbounded list would travel to the
 * plan service on every regeneration.
 *
 * @implements DataTransformerInterface<list<string>, string>
 */
final class LinesToListTransformer implements DataTransformerInterface
{
    /**
     * Generous for a real answer, small enough that the wire payload cannot be
     * used as storage.
     */
    private const int MAX_ENTRIES = 20;

    private const int MAX_LENGTH = 100;

    public function transform(mixed $value): string
    {
        if (null === $value) {
            return '';
        }

        return implode("\n", $value);
    }

    /**
     * @return list<string>
     */
    public function reverseTransform(mixed $value): array
    {
        if (null === $value) {
            return [];
        }

        $lines = preg_split('/\R/u', $value);

        if (false === $lines) {
            return [];
        }

        $entries = [];

        foreach ($lines as $line) {
            $entry = trim($line);

            if ('' === $entry) {
                continue;
            }

            $entries[] = mb_substr($entry, 0, self::MAX_LENGTH);

            if (self::MAX_ENTRIES === count($entries)) {
                break;
            }
        }

        return $entries;
    }
}
