<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Radiergummi\LaravelRls\Exceptions\InvalidContextValue;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\Tests\TestCase;
use RuntimeException;

#[TestDox('Context injection into the database')]
class ContextInjectionTest extends TestCase
{
    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    #[TestDox('Context reaches the database within a refresh database transaction')]
    public function context_reaches_db_within_refresh_database_transaction(): void
    {
        // RefreshDatabase already opened a transaction before this body ran.
        Rls::isolateTo(['tenant_id' => 'abc']);
        $this->assertSame(
            'abc',
            DB::selectOne("select rls.context('tenant_id') as value")->value,
        );
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    #[TestDox('Scoped context applies, then clears')]
    public function scoped_context_applies_then_clears(): void
    {
        Rls::isolateTo(['tenant_id' => 'xyz'], fn()
            => $this->assertSame(
                'xyz',
                DB::selectOne("select rls.context('tenant_id') as value")->value,
            ));
        $this->assertNull(DB::selectOne("select rls.context('tenant_id') as value")->value);
    }

    /**
     * @throws InvalidContextValue
     * @throws RuntimeException
     */
    #[Test]
    #[TestDox('Bypass scope sets bypass GUC')]
    public function bypass_scope_sets_bypass_guc(): void
    {
        Rls::withoutIsolation(
            'seeding',
            fn() => $this->assertTrue(DB::selectOne('select rls.bypass() as value')->value),
        );
        $this->assertFalse(DB::selectOne('select rls.bypass() as value')->value);
    }
}
