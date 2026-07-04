#!/usr/bin/env bash
set -euo pipefail
export PGPASSWORD=postgres
PSQL="psql -h 127.0.0.1 -U postgres -v ON_ERROR_STOP=1"
$PSQL -c "DROP DATABASE IF EXISTS rls_test;"
$PSQL -c "DROP ROLE IF EXISTS rls_app;"
$PSQL -c "CREATE ROLE rls_app LOGIN PASSWORD 'secret' NOSUPERUSER;"
$PSQL -c "CREATE DATABASE rls_test OWNER rls_app;"
echo "rls_app + rls_test ready"
