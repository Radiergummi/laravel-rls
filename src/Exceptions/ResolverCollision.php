<?php

namespace Radiergummi\LaravelRls\Exceptions;

use RuntimeException;

class ResolverCollision extends RuntimeException
{
    public static function forDriver(string $driver): self
    {
        return new self(
            "Another package has already registered a '{$driver}' connection " .
            'resolver, and laravel-rls would silently overwrite it — losing that ' .
            "package's connection features. To stack laravel-rls on top of it, " .
            'point rls.connection_class at a class that extends the other ' .
            "package's connection and uses the HandlesRlsContext trait.",
        );
    }
}
