# Adversarial security suite (Milestone B)

Tests written from the attacker's side: each one tries to *violate* an isolation
promise and asserts it holds. This is the milestone that earns trust for a
security library — see [`docs/MILESTONES.md`](../../docs/MILESTONES.md) for the
full threat model and "done when" criteria.

**Guiding rule:** where a real leak exists, it is characterized and documented as
a known-limit, **not** hidden or papered over by weakening the test.

All fully-written cases extend [`SecurityTestCase`](SecurityTestCase.php), which
reuses the owner-mode `documents` fixture (isolatedBy `tenant_id`, FORCE on) and
wires the BYPASSRLS admin connection. Stub cases extend PHPUnit's `TestCase` and
call `markTestIncomplete()` so `--testdox` prints them as a live checklist;
switch their base to `SecurityTestCase` when implementing.

## Threat categories → coverage

| # | Category | File | Status |
|---|----------|------|--------|
| 1 | Context leakage — stack integrity (exceptions, nesting, bypass) | [`ContextStackIntegrityTest`](ContextStackIntegrityTest.php) | ✅ written |
| 1 | Context leakage — pooling / queue / Octane (needs infra) | [`CrossWorkerLeakageTest`](CrossWorkerLeakageTest.php) | 🚧 stub |
| 2 | Bypass abuse — forged GUC inert, flag exception-safety, fail-closed | [`BypassAbuseTest`](BypassAbuseTest.php) | ✅ written |
| 3 | SQL injection — malicious *values* stay bound params | [`MaliciousValueTest`](MaliciousValueTest.php) | ✅ written (value angle) |
| 3 | Raw-SQL boundary — DB::statement, SECURITY DEFINER, views, COPY | [`RawSqlBoundaryTest`](RawSqlBoundaryTest.php) | 🚧 stub |
| 4 | Policy correctness & compounding | [`PolicyCompoundingTest`](PolicyCompoundingTest.php) | 🚧 stub |
| 5 | Role / privilege matrix | [`PrivilegeMatrixTest`](PrivilegeMatrixTest.php) | 🚧 stub |
| 6 | Value / type edge cases fail closed | [`MaliciousValueTest`](MaliciousValueTest.php) | ✅ written |
| 7 | Migration / DDL hazards | [`MigrationDdlTest`](MigrationDdlTest.php) | 🚧 stub |
| 8 | Covert channels | [`CovertChannelTest`](CovertChannelTest.php) | 🚧 stub |

## Done when (from the milestone)

- Every promise in the README "Proven in the PoC" table and every threat in the
  design threat model has at least one adversarial test attempting to violate it.
- The raw-SQL and `SECURITY DEFINER` boundaries are pinned by tests and written
  up as explicit known-limits where they genuinely leak.
- The suite rides the version matrix in CI (it is still PHPUnit).
