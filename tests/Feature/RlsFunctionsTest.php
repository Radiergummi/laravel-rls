<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Radiergummi\LaravelRls\Tests\TestCase;
use Throwable;

#[TestDox('Rls Functions')]
class RlsFunctionsTest extends TestCase
{
    #[Test]
    #[TestDox('rls.context() returns null when unset')]
    public function context_returns_null_when_unset(): void
    {
        $this->assertNull(DB::selectOne("select rls.context('tenant_id') as value")->value);
    }

    /**
     * @throws Throwable
     */
    #[Test]
    #[TestDox('rls.context() reads a transaction-local GUC')]
    public function context_reads_transaction_local_guc(): void
    {
        DB::transaction(function (): void {
            DB::statement("select set_config('app.tenant_id', 'abc', true)");
            $this->assertSame('abc', DB::selectOne("select rls.context('tenant_id') as value")->value);
        });
    }

    /**
     * @throws Throwable
     */
    #[Test]
    #[TestDox('rls.context() treats an empty string as null')]
    public function context_treats_empty_string_as_null(): void
    {
        DB::transaction(function (): void {
            DB::statement("select set_config('app.tenant_id', '', true)");
            $this->assertNull(DB::selectOne("select rls.context('tenant_id') as value")->value);
        });
    }
}
