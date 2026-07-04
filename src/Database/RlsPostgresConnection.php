<?php

namespace Radiergummi\Rls\Database;

use Illuminate\Database\PostgresConnection;

class RlsPostgresConnection extends PostgresConnection
{
    use HandlesRlsContext;
}
