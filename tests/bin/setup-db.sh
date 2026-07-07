#!/usr/bin/env bash
set -euo pipefail
export PGPASSWORD=postgres
PSQL="psql -h 127.0.0.1 -U postgres -v ON_ERROR_STOP=1"
$PSQL -c "DROP DATABASE IF EXISTS rls_test;"
$PSQL -c "DROP ROLE IF EXISTS rls_app;"
$PSQL -c "DROP ROLE IF EXISTS rls_restricted;"
$PSQL -c "DROP ROLE IF EXISTS rls_bypass;"
$PSQL -c "CREATE ROLE rls_app LOGIN PASSWORD 'secret' NOSUPERUSER;"
$PSQL -c "CREATE ROLE rls_restricted LOGIN PASSWORD 'secret' NOSUPERUSER;"
# The admin/bypass role: a non-superuser that skips RLS entirely (BYPASSRLS).
# Rls::system()/withoutIsolation() route to a connection as this role in BOTH
# role models, since the isolation predicate is now equality-only (no in-band
# bypass clause).
$PSQL -c "CREATE ROLE rls_bypass LOGIN PASSWORD 'secret' NOSUPERUSER BYPASSRLS;"
$PSQL -c "CREATE DATABASE rls_test OWNER rls_app;"
$PSQL -c "GRANT CONNECT ON DATABASE rls_test TO rls_restricted;"
$PSQL -c "GRANT CONNECT ON DATABASE rls_test TO rls_bypass;"

# Let rls_bypass reach the tables rls_app creates without a per-table grant in
# every test: default privileges apply to objects rls_app creates afterward.
PSQL_DB="psql -h 127.0.0.1 -U postgres -v ON_ERROR_STOP=1 -d rls_test"
$PSQL_DB -c "GRANT USAGE ON SCHEMA public TO rls_bypass;"
# The adversarial security suite (tests/Security/RawSqlBoundaryTest) creates a
# SECURITY DEFINER function owned by rls_bypass to pin that documented bypass
# boundary; owning a function needs CREATE on the schema (revoked from PUBLIC in
# PG 15+).
$PSQL_DB -c "GRANT CREATE ON SCHEMA public TO rls_bypass;"
$PSQL_DB -c "ALTER DEFAULT PRIVILEGES FOR ROLE rls_app IN SCHEMA public GRANT ALL ON TABLES TO rls_bypass;"
$PSQL_DB -c "ALTER DEFAULT PRIVILEGES FOR ROLE rls_app IN SCHEMA public GRANT ALL ON SEQUENCES TO rls_bypass;"

# rls_restricted is the non-owner role the privilege-matrix security tests
# (tests/Security/PrivilegeMatrixTest) read rls_app's public tables as. Unlike
# rls_bypass it does NOT skip RLS — it is an ordinary non-owner, so policies
# still confine it; these grants only give it table access to be confined on.
$PSQL_DB -c "GRANT USAGE ON SCHEMA public TO rls_restricted;"
$PSQL_DB -c "ALTER DEFAULT PRIVILEGES FOR ROLE rls_app IN SCHEMA public GRANT ALL ON TABLES TO rls_restricted;"
$PSQL_DB -c "ALTER DEFAULT PRIVILEGES FOR ROLE rls_app IN SCHEMA public GRANT ALL ON SEQUENCES TO rls_restricted;"

echo "rls_app + rls_restricted + rls_bypass + rls_test ready"
