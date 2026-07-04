<?php

declare(strict_types=1);

return [
    'prefix' => 'app.',
    'role_model' => 'owner',

    // In restricted mode, Rls::system()/withoutRls() route work to this
    // connection (a privileged owner/BYPASSRLS role). Required in restricted
    // mode; ignored in owner mode.
    'admin_connection' => null,

    'strategy' => 'transaction',
    'boundary' => 'wrap',

    // What happens when an RLS-managed table is queried with no context set:
    // 'closed' relies on the database (policy returns zero rows); 'throw' fails
    // loud in PHP with MissingTenantContext before hitting the database.
    'on_missing_context' => 'closed',

    // Runtime leak canary at each job/request boundary. If a context leaked
    // from a previous unit of work (long-lived queue/Octane workers), the stale
    // context is always cleared; this controls how loudly it is surfaced:
    // 'log' (critical log), 'throw' (fail the unit of work), or 'off'.
    'leak_canary' => 'log',

    'connection_class' => Radiergummi\LaravelRls\Database\RlsPostgresConnection::class,
];
