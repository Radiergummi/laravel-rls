<?php

namespace Radiergummi\Rls\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Radiergummi\Rls\Facades\Rls;
use Radiergummi\Rls\Tests\TestCase;

class TypedHelpersTest extends TestCase
{
    public function test_generated_sql_helper_casts_context_to_the_declared_type(): void
    {
        Rls::defineContext(fn ($c) => $c->uuid('tenant_id'));

        foreach (Rls::schema()->functionStatements() as $sql) {
            DB::statement($sql);
        }

        $uuid = '11111111-1111-1111-1111-111111111111';

        DB::transaction(function () use ($uuid) {
            DB::statement("select set_config('app.tenant_id', ?, true)", [$uuid]);

            $value = DB::selectOne('select rls.tenant_id() as v')->v;

            $this->assertSame($uuid, $value);
        });
    }

    public function test_typed_php_accessor_reads_the_dimension(): void
    {
        Rls::defineContext(fn ($c) => $c->uuid('tenant_id'));

        Rls::actingAs(['tenant_id' => 'accessor-tenant'], function () {
            $this->assertSame('accessor-tenant', Rls::tenantId());
        });
    }

    public function test_unknown_accessor_throws(): void
    {
        Rls::defineContext(fn ($c) => $c->uuid('tenant_id'));

        $this->expectException(\BadMethodCallException::class);

        Rls::somethingUndeclared();
    }
}
