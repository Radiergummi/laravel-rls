<?php

// Fixture for rls:audit scanning. Not autoloaded (nothing references it).

namespace Radiergummi\Rls\Tests\Fixtures\Audit;

class BypassSample
{
    public function export(): void
    {
        \Radiergummi\Rls\Facades\Rls::withoutRls('nightly-export', fn () => null);
    }

    public function admin(): void
    {
        $this->service->system('admin-tooling', fn () => null);
    }
}
