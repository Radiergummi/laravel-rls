<?php

declare(strict_types=1);

// Fixture for rls:audit scanning. Not autoloaded (nothing references it).

namespace Radiergummi\LaravelRls\Tests\Fixtures\Audit;

use Radiergummi\LaravelRls\Context\RlsManager;
use Radiergummi\LaravelRls\Facades\Rls;

class BypassSample
{
    public function __construct(private readonly RlsManager $service) {}

    public function export(): void
    {
        Rls::withoutIsolation('nightly-export', fn() => null);
    }

    public function admin(): void
    {
        $this->service->system('admin-tooling', fn() => null);
    }
}
