<?php

declare(strict_types=1);

namespace App\Plan;

/**
 * Reads scalars out of a decoded plan payload without ever trusting it.
 *
 * The payload is stored whole, exactly as the engine returned it, and is read
 * back years later by code that has moved on. So every value that reaches a
 * template comes through here: a field the engine has since renamed, or a row
 * written by an older engine version, degrades to an empty string or a zero
 * rather than throwing a TypeError at somebody looking at their programme.
 *
 * This is deliberately not validation. Nothing is rejected, because the plan
 * has already been generated and refusing to render it helps nobody.
 */
final class PayloadReader
{
    public static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    public static function int(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }

    public static function nullableInt(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    public static function bool(mixed $value): bool
    {
        return true === $value;
    }

    /**
     * The value at $key as a list of arrays, which is the shape every repeated
     * block in the contract has: weeks, days and exercises are all lists of
     * objects. Anything that is not one comes back empty.
     *
     * @param array<array-key, mixed> $payload
     *
     * @return list<array<array-key, mixed>>
     */
    public static function rows(array $payload, string $key): array
    {
        $raw = $payload[$key] ?? null;

        if (!is_array($raw)) {
            return [];
        }

        $rows = [];

        foreach ($raw as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * The value at $key as a list of strings. Used for red_flags, which is the
     * one list of bare scalars crossing the wire.
     *
     * @param array<array-key, mixed> $payload
     *
     * @return list<string>
     */
    public static function strings(array $payload, string $key): array
    {
        $raw = $payload[$key] ?? null;

        if (!is_array($raw)) {
            return [];
        }

        $strings = [];

        foreach ($raw as $value) {
            if (is_string($value)) {
                $strings[] = $value;
            }
        }

        return $strings;
    }
}
