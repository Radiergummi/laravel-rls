<?php

declare(strict_types=1);

// Fixture for rls:audit scanning. Not autoloaded (nothing references it).

namespace Radiergummi\LaravelRls\Tests\Fixtures\Audit;

use Radiergummi\LaravelRls\Context\RlsManager;
use Radiergummi\LaravelRls\Exceptions\InvalidContextValue;
use Radiergummi\LaravelRls\Facades\Rls;
use RuntimeException;

readonly class BypassSample
{
    public function __construct(private RlsManager $service) {}

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    public function export(): void
    {
        Rls::withoutIsolation('nightly-export', static fn() => null);
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    public function admin(): void
    {
        $this->service->system('admin-tooling', static fn() => null);
    }
}
