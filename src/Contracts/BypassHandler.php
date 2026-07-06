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

namespace Radiergummi\LaravelRls\Contracts;

use Closure;
use Illuminate\Container\Attributes\Bind;
use Radiergummi\LaravelRls\Exceptions\AdminConnectionRequired;
use Radiergummi\LaravelRls\Support\DefaultBypassHandler;

#[Bind(DefaultBypassHandler::class)]
interface BypassHandler
{
    /**
     * Handle a bypass request by switching to the admin connection and executing the given callback
     *
     * @template T
     *
     * @param non-empty-string $reason
     * @param Closure(): T     $callback
     *
     * @return T
     *
     * @throws AdminConnectionRequired
     */
    public function __invoke(string $reason, Closure $callback): mixed;
}
