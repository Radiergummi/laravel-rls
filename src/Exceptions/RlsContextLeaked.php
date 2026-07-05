<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Exceptions;

use RuntimeException;

class RlsContextLeaked extends RuntimeException
{
    /** @param list<string> $isolationKeys */
    public static function at(string $boundary, array $isolationKeys): self
    {
        $keys = $isolationKeys === [] ? '(bypass scope)' : implode(', ', $isolationKeys);

        return new self(
            "RLS context leaked into a new {$boundary} (the context stack was not empty at"
            . " its start). Leaked isolation keys: {$keys}. This means a previous {$boundary} on this"
            . ' worker did not clear its context, which is a cross-context isolation hazard.'
            . ' The stale context has been cleared.',
        );
    }
}
