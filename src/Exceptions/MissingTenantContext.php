<?php

namespace Radiergummi\Rls\Exceptions;

use RuntimeException;

class MissingTenantContext extends RuntimeException
{
    public static function forQuery(string $query): self
    {
        return new self(
            'Query against an RLS-managed table with no context set. ' .
            "Establish context with Rls::actingAs(), or wrap in Rls::withoutRls() to bypass. Query: {$query}",
        );
    }
}
