<?php

return [
    'prefix' => 'app.',
    'role_model' => 'owner',
    'strategy' => 'transaction',
    'boundary' => 'wrap',
    'connection_class' => \Radiergummi\Rls\Database\RlsPostgresConnection::class,
];
