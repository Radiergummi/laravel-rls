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
    'connection_class' => \Radiergummi\Rls\Database\RlsPostgresConnection::class,
];
