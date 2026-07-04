#!/usr/bin/env bash
# Bring up a PgBouncer in transaction pooling mode in front of the test
# Postgres (rls-pg on 5432), listening on 6432. Enables PgBouncerTest, which is
# skipped when 127.0.0.1:6432 is unreachable.
set -euo pipefail

docker rm -f rls-pgbouncer >/dev/null 2>&1 || true

docker run -d --name rls-pgbouncer \
  -e DB_HOST=host.docker.internal -e DB_PORT=5432 \
  -e DB_USER=rls_app -e DB_PASSWORD=secret -e DB_NAME=rls_test \
  -e POOL_MODE=transaction -e AUTH_TYPE=scram-sha-256 \
  -e MAX_CLIENT_CONN=100 -e DEFAULT_POOL_SIZE=20 \
  -p 6432:5432 edoburu/pgbouncer:latest

echo "pgbouncer (transaction mode) up on 127.0.0.1:6432 -> rls-pg:5432"
echo "tear down with: docker rm -f rls-pgbouncer"
