<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use BadMethodCallException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Radiergummi\LaravelRls\Context\ContextSchema;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\Tests\TestCase;

class TypedHelpersTest extends TestCase
{
    #[Test]
    public function generated_sql_helper_casts_context_to_the_declared_type(): void
    {
        Rls::defineContext(static fn(ContextSchema $context) => $context->uuid('tenant_id'));

        foreach (Rls::schema()?->functionStatements() ?? [] as $sql) {
            DB::statement($sql);
        }

        $uuid = '11111111-1111-1111-1111-111111111111';

        DB::transaction(function () use ($uuid) {
            DB::statement("select set_config('app.tenant_id', ?, true)", [$uuid]);

            $value = DB::selectOne('select rls.tenant_id() as v')->v;

            $this->assertSame($uuid, $value);
        });
    }

    #[Test]
    public function typed_php_accessor_reads_the_dimension(): void
    {
        Rls::defineContext(fn($c) => $c->uuid('tenant_id'));

        $uuid = '22222222-2222-2222-2222-222222222222';

        Rls::actingAs(['tenant_id' => $uuid], function () use ($uuid) {
            // tenantId() is a dynamic accessor via RlsManager::__call (snake_cased
            // to the 'tenant_id' dimension) — no static signature to resolve.
            // @phpstan-ignore staticMethod.notFound (magic __call accessor for a declared dimension)
            $this->assertSame($uuid, Rls::tenantId());
        });
    }

    #[Test]
    public function unknown_accessor_throws(): void
    {
        Rls::defineContext(fn($c) => $c->uuid('tenant_id'));

        $this->expectException(BadMethodCallException::class);

        // @phpstan-ignore staticMethod.notFound (intentionally calls an undeclared accessor to assert __call throws)
        Rls::somethingUndeclared();
    }
}
