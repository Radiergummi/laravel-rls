# Contributing

Thanks for your interest. This project is early-stage and feedback on the
**design and approach** is as valuable as code — see the
[design and threat model](docs/superpowers/specs/2026-07-04-laravel-postgresql-rls-design.md)
and [milestones](docs/MILESTONES.md) for where it's headed.

## Development setup

Requires PHP 8.2+, Composer, and Docker.

```bash
composer install

# PostgreSQL plus the roles the suite needs (rls_app owner, rls_restricted
# non-owner, rls_bypass BYPASSRLS admin).
docker run -d --name rls-pg -e POSTGRES_PASSWORD=postgres -p 5432:5432 postgres:18
./tests/bin/setup-db.sh

composer test      # phpunit
composer lint      # phpstan
composer format    # pint (write); pint --test to check only

docker rm -f rls-pg
```

**The one gotcha that will waste your afternoon:** the suite connects as the
non-superuser `rls_app`, not `postgres`. Superusers and `BYPASSRLS` roles skip
RLS entirely, so testing as one makes every isolation assertion falsely pass.

`PgBouncerTest` is skipped unless a bouncer is reachable on `127.0.0.1:6432`
(`./tests/bin/setup-pgbouncer.sh`); the latency sweep needs Toxiproxy
(`./tests/bin/setup-toxiproxy.sh`). CI runs without either and they skip
cleanly.

## Before you open a PR

- Add or update a test. Each behavior in this package has a focused test, and
  reading them is the fastest way to understand it — match that.
- `composer test`, `composer lint`, and `vendor/bin/pint --test` must all pass;
  CI runs the same three.
- Keep changes surgical and match the surrounding style.
- Note anything user-facing in [`CHANGELOG.md`](CHANGELOG.md) under
  `Unreleased`.

## Security issues

Do not open a public issue for anything that could break tenant isolation. See
[`SECURITY.md`](SECURITY.md) for private reporting.
