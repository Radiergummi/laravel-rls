<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature\Bench;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Radiergummi\LaravelRls\Bench\Boot;
use Radiergummi\LaravelRls\Bench\Schema;
use Radiergummi\LaravelRls\Bench\TableSet;

#[TestDox('Bench Schema')]
class SchemaTest extends TestCase
{
    #[Test]
    #[TestDox('seed() creates and fills the three tables with identical deterministic data')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function seeds_identical_data(): void
    {
        $app = Boot::app();
        $schema = new Schema($app);

        try {
            $tables = $schema->seed('1k');
            $admin = $app->make('db')->connection('pgsql_admin');

            // @phpstan-ignore method.alreadyNarrowedType (defensive assertion on the DTO return type)
            $this->assertInstanceOf(TableSet::class, $tables);
            $this->assertSame(1000, (int) $admin->table(TableSet::FLOOR)->count());
            $this->assertSame(1000, (int) $admin->table(TableSet::CONTROL)->count());
            $this->assertSame(1000, (int) $admin->table(TableSet::TREATMENT)->count());

            // The probe row exists in the probe tenant.
            $row = $admin->table(TableSet::TREATMENT)->where('id', $tables->probeRowId)->first();
            $this->assertNotNull($row);
            $this->assertSame($tables->probeTenantId, $row->tenant_id);

            // The scoping column is indexed on the treatment table.
            $indexed = $admin->selectOne(
                "select 1 as ok from pg_indexes where tablename = ? and indexdef like '%tenant_id%'",
                [TableSet::TREATMENT],
            );
            $this->assertNotNull($indexed, 'treatment.tenant_id must be indexed');
        } finally {
            $schema->drop();
        }
    }
}
