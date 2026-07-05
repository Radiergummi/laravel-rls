<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Radiergummi\LaravelRls\Tests\TestCase;

class RlsFunctionsTest extends TestCase
{
    #[Test]
    public function context_returns_null_when_unset(): void
    {
        $this->assertNull(DB::selectOne("select rls.context('tenant_id') as v")->v);
    }

    #[Test]
    public function context_reads_transaction_local_guc(): void
    {
        DB::transaction(function () {
            DB::statement("select set_config('app.tenant_id', 'abc', true)");
            $this->assertSame('abc', DB::selectOne("select rls.context('tenant_id') as v")->v);
        });
    }

    #[Test]
    public function context_treats_empty_string_as_null(): void
    {
        DB::transaction(function () {
            DB::statement("select set_config('app.tenant_id', '', true)");
            $this->assertNull(DB::selectOne("select rls.context('tenant_id') as v")->v);
        });
    }

    #[Test]
    public function bypass_defaults_to_false(): void
    {
        $this->assertFalse(DB::selectOne('select rls.bypass() as v')->v);
    }

    #[Test]
    public function bypass_reads_guc(): void
    {
        DB::transaction(function () {
            DB::statement("select set_config('app.bypass', 'on', true)");
            $this->assertTrue(DB::selectOne('select rls.bypass() as v')->v);
        });
    }
}
