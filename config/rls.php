<?php

declare(strict_types=1);

return [
    'prefix' => 'app.',
    'role_model' => 'owner',

    // Rls::system()/withoutIsolation() route work to this connection (a
    // privileged BYPASSRLS role). Required for bypass in BOTH role models:
    // there is no in-band bypass, so without it these calls hard-fail.
    'admin_connection' => null,

    'strategy' => 'transaction',
    'boundary' => 'wrap',

    // What happens when an RLS-managed table is queried with no context set:
    // 'closed' relies on the database (policy returns zero rows); 'throw' fails
    // loud in PHP with MissingIsolationContext before hitting the database.
    'on_missing_context' => 'closed',

    // What happens when an isolation key changes to a different value while a
    // transaction is already open (transaction strategy): 'allow' lets the
    // transaction span both scopes; 'throw' fails loud with NestedTenantContext
    // to catch a transaction accidentally straddling two tenants. Kept opt-in
    // because the standard RefreshDatabase test harness runs each test inside a
    // transaction, where switching scope between assertions is normal.
    'on_nested_change' => 'allow',

    // Runtime leak canary at each job/request boundary. If a context leaked
    // from a previous unit of work (long-lived queue/Octane workers), the stale
    // context is always cleared; this controls how loudly it is surfaced:
    // 'log' (critical log), 'throw' (fail the unit of work), or 'off'.
    'leak_canary' => 'log',

    'connection_class' => Radiergummi\LaravelRls\Database\RlsPostgresConnection::class,
];
