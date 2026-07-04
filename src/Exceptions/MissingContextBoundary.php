<?php

namespace Radiergummi\LaravelRls\Exceptions;

use RuntimeException;

class MissingContextBoundary extends RuntimeException
{
    public static function forQuery(string $query): self
    {
        return new self(
            'RLS boundary is "explicit": a context-bearing query on an RLS-managed table ran ' .
            "outside a transaction. Wrap the work in DB::transaction() or apply the request middleware. Query: {$query}",
        );
    }
}
