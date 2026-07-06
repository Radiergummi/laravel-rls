<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Exceptions;

use RuntimeException;

class AdminConnectionRequired extends RuntimeException
{
    public static function forReason(string $reason): self
    {
        return new self(
            'Rls::system()/withoutIsolation() requires an admin connection '
            . "(reason: \"{$reason}\"). Set rls.admin_connection to a privileged "
            . '(BYPASSRLS) connection.',
        );
    }
}
