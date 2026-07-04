<?php

namespace Radiergummi\Rls\Tests\Support;

use Radiergummi\Rls\Database\HandlesRlsContext;
use Tpetry\PostgresqlEnhanced\PostgresEnhancedConnection;

/**
 * Composed connection: tpetry's enhanced connection + our RLS trait. This is
 * how a user stacks laravel-rls on top of another pgsql connection package.
 */
class RlsEnhancedConnection extends PostgresEnhancedConnection
{
    use HandlesRlsContext;
}
