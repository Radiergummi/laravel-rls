<?php

namespace Radiergummi\Rls\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Radiergummi\Rls\Tests\TestCase;

class RlsCheckCommandTest extends TestCase
{
    public function test_passes_when_a_tenant_scoped_table_is_protected(): void
    {
        Schema::create('checked_things', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->scopedBy('tenant_id');
        });

        try {
            $this->artisan('rls:check')->assertExitCode(0);
        } finally {
            Schema::dropIfExists('checked_things');
        }
    }

    public function test_fails_when_a_tenant_scoped_table_is_unprotected(): void
    {
        // tenant_id column but no scopedBy() -> no RLS, no policy.
        Schema::create('unchecked_things', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
        });

        try {
            $this->artisan('rls:check')
                ->expectsOutputToContain('unchecked_things')
                ->assertExitCode(1);
        } finally {
            Schema::dropIfExists('unchecked_things');
        }
    }
}
