<?php

declare(strict_types=1);

/**
 * PHPStan bootstrap.
 *
 * larastan resolves Blueprint (and other) macros by reflecting the registered
 * `Blueprint::$macros` — but only for macros that are actually registered when
 * analysis runs. Analysing this package in isolation never boots a full Laravel
 * app, so RlsServiceProvider never runs and the RLS macros (`isolatedBy`,
 * `enableRowLevelSecurity`, `forceRowLevelSecurity`) are absent, making
 * `$table->isolatedBy()` look undefined on the plain Blueprint.
 *
 * Registering them here mirrors what auto-discovery does in a consuming app, so
 * static analysis matches runtime. Consumers get this for free: their larastan
 * boots their app, which registers our provider.
 */

require_once __DIR__ . '/vendor/autoload.php';

Radiergummi\LaravelRls\Schema\RlsSchemaMacros::register();
