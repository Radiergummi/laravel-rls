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

namespace Radiergummi\LaravelRls\Listeners;

use Illuminate\Container\Attributes\Log;
use Psr\Log\LoggerInterface;
use Radiergummi\LaravelRls\Events\RlsBypassed;

readonly class RlsBypassedListener
{
    public function __construct(
        #[Log('rls')]
        private LoggerInterface $logger,
    ) {}

    public function handle(RlsBypassed $event): void
    {
        $this->logger->warning("RLS bypassed: {$event->reason}", ['reason' => $event->reason]);
    }
}
