<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Bench;

/**
 * One endpoint measurement config: a connection path crossed with a strategy/boundary.
 *
 * `$unsafe` encodes the pgbouncer·session incompatibility "by construction" — a session GUC set
 * outside a transaction does not survive PgBouncer transaction pooling, and no single-client guard
 * can observe it (the reused backend returns correct rows). It is flagged, never measured.
 */
final readonly class EndpointConfig
{
    public function __construct(
        public string $label,
        public string $connectionName,
        public string $strategy,      // 'transaction' | 'session'
        public bool $oneTransaction,
        public string $boundaryLabel, // 'wrap' | 'request' | '—'
        public bool $unsafe = false,
    ) {}

    /**
     * The six connection-path × strategy/boundary configs, in matrix order.
     *
     * @return list<self>
     */
    public static function matrix(): array
    {
        return [
            new self('direct·transaction·wrap', 'pgsql', 'transaction', false, 'wrap'),
            new self('direct·transaction·request', 'pgsql', 'transaction', true, 'request'),
            new self('direct·session', 'pgsql', 'session', false, '—'),
            new self('pgbouncer·transaction·wrap', 'pgsql_pgbouncer', 'transaction', false, 'wrap'),
            new self('pgbouncer·transaction·request', 'pgsql_pgbouncer', 'transaction', true, 'request'),
            new self('pgbouncer·session', 'pgsql_pgbouncer', 'session', false, '—', true),
        ];
    }
}
