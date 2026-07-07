# Adversarial security suite (Milestone B)

Tests written from the attacker's side: each one tries to *violate* an isolation
promise and asserts it holds. This is the milestone that earns trust for a
security library — see [`docs/MILESTONES.md`](../../docs/MILESTONES.md) for the
full threat model and "done when" criteria.

**Guiding rule:** where a real leak exists, it is characterized and documented as
a known-limit, **not** hidden or papered over by weakening the test.

Most cases extend [`SecurityTestCase`](SecurityTestCase.php), which reuses the
owner-mode `documents` fixture (isolatedBy `tenant_id`, FORCE on) and wires the
BYPASSRLS admin connection. Cases that need committed data readable across
several role connections (`PrivilegeMatrixTest`, `CrossWorkerLeakageTest`) extend
Orchestra's `TestCase` directly instead — `RefreshDatabase` wraps each test in a
single transaction, which those cases specifically need to avoid.

Cases that still need live infrastructure (a real PgBouncer, a queue worker,
Octane) are `markTestIncomplete()` methods inside their category's class, so
`--testdox` prints them as a live checklist cross-referenced to the feature test
that exercises the happy path.

## Threat categories → coverage

| # | Category | File | Status |
|---|----------|------|--------|
| 1 | Context leakage — stack integrity (exceptions, nesting, bypass) | [`ContextStackIntegrityTest`](ContextStackIntegrityTest.php) | ✅ written |
| 1 | Context leakage — pooling / queue / Octane | [`CrossWorkerLeakageTest`](CrossWorkerLeakageTest.php) | ✅ core written; live-infra cases marked |
| 2 | Bypass abuse — forged GUC inert, flag exception-safety, fail-closed | [`BypassAbuseTest`](BypassAbuseTest.php) | ✅ written |
| 3 | SQL injection — malicious *values* stay bound params | [`MaliciousValueTest`](MaliciousValueTest.php) | ✅ written (value angle) |
| 3 | Raw-SQL boundary — raw reads/writes, fail-loud guard, SECURITY DEFINER | [`RawSqlBoundaryTest`](RawSqlBoundaryTest.php) | ✅ written (core) |
| 4 | Policy correctness & compounding | [`PolicyCompoundingTest`](PolicyCompoundingTest.php) | ✅ written |
| 5 | Role / privilege matrix | [`PrivilegeMatrixTest`](PrivilegeMatrixTest.php) | ✅ written |
| 6 | Value / type edge cases fail closed | [`MaliciousValueTest`](MaliciousValueTest.php) | ✅ written |
| 7 | Migration / DDL hazards | [`MigrationDdlTest`](MigrationDdlTest.php) | ✅ written |
| 8 | Covert channels | [`CovertChannelTest`](CovertChannelTest.php) | ✅ written (deterministic; timing documented) |

`RawSqlBoundaryTest` covers raw `DB::select`/`update`/`delete` confinement, the
fail-loud guard's quoted-vs-unquoted boundary, and the `SECURITY DEFINER` bypass.
Still open for category 3: views (`security_invoker`), CTEs, `COPY`, `TRUNCATE`,
and triggers.

## Done when (from the milestone)

- Every promise in the README "Proven in the PoC" table and every threat in the
  design threat model has at least one adversarial test attempting to violate it.
- The raw-SQL and `SECURITY DEFINER` boundaries are pinned by tests and written
  up as explicit known-limits where they genuinely leak.
- The suite rides the version matrix in CI (it is still PHPUnit).
