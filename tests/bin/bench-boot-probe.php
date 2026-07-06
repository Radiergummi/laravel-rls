<?php

declare(strict_types=1);

// Regression probe for the bench-boot env fix (bench/Boot.php forcing APP_ENV=testing).
//
// RlsServiceProvider installs the container's environment resolver — required for class-level
// #[Bind] attribute bindings such as BypassHandler -> DefaultBypassHandler — only under the
// 'testing' env. A bare `composer bench` shell has no APP_ENV=testing (phpunit sets it, which
// masks the bug when run.php is spawned from the suite), so Boot::app() must force it. Run this
// script with APP_ENV stripped from the environment: if the harness boots and resolves the
// BypassHandler binding it prints OK; without the fix it dies with "not instantiable". No database
// is touched — connections are lazy, so this stays fast and needs no running Postgres.

use Radiergummi\LaravelRls\Bench\Boot;
use Radiergummi\LaravelRls\Contracts\BypassHandler;

require __DIR__ . '/../../vendor/autoload.php';

$app = Boot::app();
$app->make(BypassHandler::class);

echo 'OK';
