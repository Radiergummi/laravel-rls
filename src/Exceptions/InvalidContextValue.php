<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Exceptions;

use InvalidArgumentException;
use Stringable;

class InvalidContextValue extends InvalidArgumentException
{
    public static function forDimension(string $name, string $type, mixed $value): self
    {
        $given = is_scalar($value) || $value instanceof Stringable
            ? (string) $value
            : get_debug_type($value);

        return new self(
            "RLS context value for '{$name}' is not a valid {$type}: '{$given}'. "
            . 'Values are validated against the declared context schema before they '
            . 'can reach the database (a malformed value would otherwise error on '
            . 'every query).',
        );
    }
}
