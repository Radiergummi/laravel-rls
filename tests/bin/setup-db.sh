#!/usr/bin/env bash
set -euo pipefail
export PGPASSWORD=postgres
PSQL="psql -h 127.0.0.1 -U postgres -v ON_ERROR_STOP=1"
$PSQL -c "DROP DATABASE IF EXISTS rls_test;"
$PSQL -c "DROP ROLE IF EXISTS rls_app;"
$PSQL -c "DROP ROLE IF EXISTS rls_restricted;"
$PSQL -c "CREATE ROLE rls_app LOGIN PASSWORD 'secret' NOSUPERUSER;"
$PSQL -c "CREATE ROLE rls_restricted LOGIN PASSWORD 'secret' NOSUPERUSER;"
$PSQL -c "CREATE DATABASE rls_test OWNER rls_app;"
$PSQL -c "GRANT CONNECT ON DATABASE rls_test TO rls_restricted;"
echo "rls_app + rls_restricted + rls_test ready"
