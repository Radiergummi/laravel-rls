<?php

/**
 * This file is part of laravel-rls, a Matchory application.
 *
 * Unauthorized copying of this file, via any medium, is strictly prohibited.
 * Its contents are strictly confidential and proprietary.
 *
 * @copyright 2020–2026 Matchory GmbH · All rights reserved
 * @author    Moritz Friedrich <moritz@matchory.com>
 */

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Support;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Radiergummi\LaravelRls\Contracts\BypassHandler;
use Radiergummi\LaravelRls\Exceptions\AdminConnectionRequired;

use function assert;
use function is_string;

readonly class DefaultBypassHandler implements BypassHandler
{
    public function __construct(
        private DatabaseManager $databaseManager,
        private Repository $config,
    ) {}

    public function __invoke(string $reason, Closure $callback): mixed
    {
        // Read the admin connection at invoke time, not construction: the handler is resolved once
        // at boot and lives for the app's lifetime, so a value injected at construction would go
        // stale if rls.admin_connection changes (and would ignore config set after boot).
        $adminConnection = $this->config->get('rls.admin_connection');

        if ($adminConnection === null) {
            throw AdminConnectionRequired::forReason($reason);
        }
        assert(is_string($adminConnection));

        $previous = $this->databaseManager->getDefaultConnection();
        $this->databaseManager->setDefaultConnection($adminConnection);

        try {
            return $callback();
        } finally {
            $this->databaseManager->setDefaultConnection($previous);
        }
    }
}
