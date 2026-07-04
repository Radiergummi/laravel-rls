<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Database;

use Illuminate\Database\PostgresConnection;

class RlsPostgresConnection extends PostgresConnection
{
    use HandlesRlsContext;
}
