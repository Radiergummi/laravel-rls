<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests;

use Illuminate\Database\ConnectionInterface;
use Radiergummi\LaravelRls\Support\RlsFunctions;

/**
 * Shared fixtures for the committed-seed RLS tests — the ones that bypass
 * RefreshDatabase so their data is committed and readable across several role
 * connections (OwnerModeBypassTest, RestrictedIsolationTest, PrivilegeMatrixTest,
 * CrossWorkerLeakageTest).
 *
 * Those tests each need a distinct connection topology, table, and role model, so
 * they keep their own defineEnvironment/setUp; this trait only removes the three
 * pieces they would otherwise hand-roll identically: the connection config, the
 * rls-function install, and the isolation-policy DDL.
 */
trait CommittedRlsFixtures
{
    /**
     * A pgsql connection config for the given role. Every committed-seed test
     * connects to rls_test on localhost; only the role — and, for the superuser,
     * the password — varies.
     *
     * @return array<string, mixed>
     */
    protected function rlsConnection(string $user, string $password = 'secret'): array
    {
        return [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 5432,
            'database' => 'rls_test',
            'username' => $user,
            'password' => $password,
            'charset' => 'utf8',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ];
    }

    protected function installRlsFunctions(ConnectionInterface $connection): void
    {
        foreach (RlsFunctions::statements() as $sql) {
            $connection->statement($sql);
        }
    }

    /**
     * Attach the standard two-policy isolation setup to an existing table: a
     * permissive base policy and a restrictive equality policy on the isolation
     * column. Mirrors what isolatedBy() installs; the policies are named for the
     * test rather than following the macro's naming.
     */
    protected function enableIsolation(
        ConnectionInterface $connection,
        string $table,
        string $policyPrefix,
        bool $force,
        string $column = 'tenant_id',
        string $type = 'uuid',
    ): void {
        $connection->statement("alter table {$table} enable row level security");

        if ($force) {
            $connection->statement("alter table {$table} force row level security");
        }

        $connection->statement(
            "create policy {$policyPrefix}_access on {$table} "
            . 'as permissive for all using (true) with check (true)',
        );

        $predicate = "{$column} = rls.context('{$column}')::{$type}";
        $connection->statement(
            "create policy {$policyPrefix}_iso on {$table} as restrictive for all "
            . "using ({$predicate}) with check ({$predicate})",
        );
    }
}
