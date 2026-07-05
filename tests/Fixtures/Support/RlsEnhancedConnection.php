<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Fixtures\Support;

use Radiergummi\LaravelRls\Database\HandlesRlsContext;
use Tpetry\PostgresqlEnhanced\PostgresEnhancedConnection;

/**
 * Composed connection: tpetry's enhanced connection and our RLS trait.
 *
 * This is how a user stacks laravel-rls on top of another pgsql connection package.
 */
class RlsEnhancedConnection extends PostgresEnhancedConnection
{
    use HandlesRlsContext;
}
