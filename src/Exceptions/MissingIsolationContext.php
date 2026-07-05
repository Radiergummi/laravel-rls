<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Exceptions;

use RuntimeException;

class MissingIsolationContext extends RuntimeException
{
    public static function forQuery(string $query): self
    {
        return new self(
            'Query against an RLS-managed table with no context set.'
            . ' Establish context with Rls::isolateTo(), or wrap in Rls::withoutIsolation() to bypass.'
            . " Query: {$query}",
        );
    }
}
