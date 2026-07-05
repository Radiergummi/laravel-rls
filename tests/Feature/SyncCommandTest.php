<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Radiergummi\LaravelRls\Context\ContextSchema;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\Tests\TestCase;

use function assert;

#[TestDox('rls:sync Command')]
class SyncCommandTest extends TestCase
{
    #[Test]
    #[TestDox('rls:sync generates a typed SQL helper function from the declared context schema')]
    public function generates_typed_helper_from_the_declared_schema(): void
    {
        Rls::defineContext(static fn(ContextSchema $context) => $context->uuid('tenant_id'));

        // run() forces the command to execute now; a PendingCommand held in a
        // variable otherwise defers execution to its destructor, which fires
        // *after* the assertions below — the helper would not yet exist.
        $result = $this->artisan('rls:sync');
        assert($result instanceof PendingCommand);
        $result->assertExitCode(0)->run();

        $exists = DB::selectOne(
            'select 1 as e from pg_proc p join pg_namespace n on n.oid = p.pronamespace '
            . "where n.nspname = 'rls' and p.proname = 'tenant_id'",
        );
        $this->assertNotNull($exists, 'expected rls.tenant_id() to be created');
    }

    #[Test]
    #[TestDox('rls:sync is a no-op when no context schema is declared')]
    public function is_a_noop_without_a_declared_schema(): void
    {
        $result = $this->artisan('rls:sync');
        assert($result instanceof PendingCommand);
        $result
            ->expectsOutputToContain('No context schema')
            ->assertExitCode(0);
    }
}
