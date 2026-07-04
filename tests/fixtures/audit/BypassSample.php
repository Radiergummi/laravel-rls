<?php

// Fixture for rls:audit scanning. Not autoloaded (nothing references it).

namespace Radiergummi\LaravelRls\Tests\Fixtures\Audit;

class BypassSample
{
    public function export(): void
    {
        \Radiergummi\LaravelRls\Facades\Rls::withoutRls('nightly-export', fn () => null);
    }

    public function admin(): void
    {
        $this->service->system('admin-tooling', fn () => null);
    }
}
