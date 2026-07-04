<?php

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

    'connection_class' => \Radiergummi\LaravelRls\Database\RlsPostgresConnection::class,
];
