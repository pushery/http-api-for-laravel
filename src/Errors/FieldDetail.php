<?php

declare(strict_types=1);

namespace Pushery\HttpApi\Errors;

use InvalidArgumentException;

/**
 * The detail an invalid field answers with, named by the rule that refused it.
 *
 * A failed validation answers each invalid field with `validation_failed`, and Laravel's message for the field never
 * reaches the client. A rule whose message is made with {@see named()} names a detail of the code instead, one the
 * application declares under `validation_failed` in `http-api.errors.details`, with the values of its placeholders,
 * and the field answers with that detail. The message carries a name and values, never a text, so every text a client
 * reads is still one the catalog or the application declared.
 *
 * A name the code has no detail of, and a value against the rules of the catalog's texts, answer with the code's
 * `default`, as a field whose rule names no detail does.
 */
final class FieldDetail
{
    /** What a message that names a detail begins with. */
    private const string PREFIX = 'http-api:detail:';

    /**
     * The message of a rule whose field answers with the detail of this name.
     *
     * A value can be a placeholder of Laravel's own message, `:max` for instance, which Laravel fills with the
     * parameter of the rule before the message reaches the exception. `:input` is refused: Laravel fills it with the
     * value the request sent, and no value of a request becomes part of a detail.
     *
     * @param  string  $detail  the name of a detail of `validation_failed`, `items_left` for instance
     * @param  array<string, string>  $values  the values of the detail's placeholders, by name
     *
     * @throws InvalidArgumentException for a value that names `:input`
     */
    public static function named(string $detail, array $values = []): string
    {
        foreach ($values as $name => $value) {
            if (stripos($value, ':input') !== false) {
                throw new InvalidArgumentException(sprintf('The value of {%s} names :input, the value of the request, which no detail carries.', $name));
            }
        }

        return self::PREFIX.json_encode(['detail' => $detail, 'values' => $values], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The name of the detail and the values a message names, or null for a message that names none.
     *
     * @return array{0: string, 1: array<string, string>}|null
     */
    public static function in(string $message): ?array
    {
        if (! str_starts_with($message, self::PREFIX)) {
            return null;
        }

        $named = json_decode(substr($message, strlen(self::PREFIX)), true);

        if (! is_array($named) || ! is_string($named['detail'] ?? null) || ! is_array($named['values'] ?? null)) {
            return null;
        }

        $values = [];

        foreach ($named['values'] as $name => $value) {
            if (! is_string($name) || ! is_string($value)) {
                return null;
            }

            $values[$name] = $value;
        }

        return [$named['detail'], $values];
    }
}
