<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Exceptions;

use RuntimeException;

class NestedTenantContext extends RuntimeException
{
    public static function forChangedKey(string $key, string $from, string $to): self
    {
        return new self(
            "RLS isolation key \"{$key}\" changed from \"{$from}\" to \"{$to}\" inside an open"
            . ' transaction. A single transaction must not span two scopes: rows read before the'
            . ' switch were visible under the old scope, so the transaction would straddle both.'
            . ' Commit the current work first, or move the context change outside the transaction.',
        );
    }
}
