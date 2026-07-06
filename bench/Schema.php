<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Schema\Blueprint;
use InvalidArgumentException;
use Radiergummi\LaravelRls\Support\RlsFunctions;

use function array_chunk;
use function sprintf;

final class Schema
{
    private const TENANTS = 100;
    private const PROBE_TENANT = 42;

    public function __construct(private readonly Application $app) {}

    public function rowCount(string $scale): int
    {
        return match ($scale) {
            '1k' => 1000,
            '100k' => 100000,
            default => throw new InvalidArgumentException("Unknown scale: {$scale}"),
        };
    }

    public function seed(string $scale): TableSet
    {
        $rows = $this->rowCount($scale);
        $this->drop();

        // The treatment table's isolatedBy() macro generates a policy referencing
        // rls.context(), and Boot doesn't run migrations, so the helper schema/function
        // must be installed before creating that table on a fresh database.
        $owner = $this->app->make('db')->connection();

        foreach (RlsFunctions::statements() as $sql) {
            $owner->statement($sql);
        }

        $builder = $owner->getSchemaBuilder();

        // floor + control are plain tables; treatment is isolated via the macro. All three carry
        // the same columns and a tenant_id index so index behaviour is measured, not absent.
        foreach ([TableSet::FLOOR, TableSet::CONTROL] as $table) {
            $builder->create($table, static function (Blueprint $t): void {
                $t->uuid('id')->primary();
                $t->uuid('tenant_id')->index();
                $t->integer('n')->index();
                $t->string('payload');
            });
        }

        $builder->create(TableSet::TREATMENT, static function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->integer('n')->index();
            $t->string('payload');
            $t->isolatedBy('tenant_id');
        });

        $this->fill($rows);

        return new TableSet(
            scale: $scale,
            probeTenantId: $this->tenantId(self::PROBE_TENANT),
            probeRowId: $this->rowId(self::PROBE_TENANT), // row n = 42 belongs to tenant 42
            probeRangeLo: 0,
            probeRangeHi: (int) ($rows / 10),
        );
    }

    public function drop(): void
    {
        // Tables are owned by the default connection's role (rls_app); DROP TABLE requires
        // ownership, and pgsql_admin (rls_bypass) is neither owner nor superuser.
        $builder = $this->app->make('db')->connection()->getSchemaBuilder();
        $builder->dropIfExists(TableSet::TREATMENT);
        $builder->dropIfExists(TableSet::CONTROL);
        $builder->dropIfExists(TableSet::FLOOR);
    }

    private function fill(int $rows): void
    {
        // Seed via the BYPASSRLS admin connection so the FORCE-bound treatment table's WITH CHECK
        // does not reject the cross-tenant bulk load. Identical rows into all three tables.
        $admin = $this->app->make('db')->connection('pgsql_admin');

        $records = [];

        for ($i = 0; $i < $rows; $i++) {
            $records[] = [
                'id' => $this->rowId($i),
                'tenant_id' => $this->tenantId($i % self::TENANTS),
                'n' => $i,
                'payload' => 'x',
            ];
        }

        $chunks = array_chunk($records, 1000);

        foreach ([TableSet::FLOOR, TableSet::CONTROL, TableSet::TREATMENT] as $table) {
            foreach ($chunks as $chunk) {
                $admin->table($table)->insert($chunk);
            }
        }
    }

    private function tenantId(int $t): string
    {
        return sprintf('00000000-0000-0000-0000-%012d', $t);
    }

    private function rowId(int $i): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $i);
    }
}
