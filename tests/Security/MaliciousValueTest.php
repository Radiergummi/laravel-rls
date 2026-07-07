<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Security;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Radiergummi\LaravelRls\Context\ContextSchema;
use Radiergummi\LaravelRls\Exceptions\InvalidContextValue;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\Tests\Fixtures\Models\Document;

/**
 * Threat category 6 — malicious / malformed context values, plus the
 * injection-value corner of category 3. Every ill-formed value must fail
 * *closed* (rejected in PHP, or zero rows), never open; and a value that happens
 * to contain SQL must stay a bound parameter, never interpolated.
 *
 * @see docs/MILESTONES.md — Milestone B, threat categories 6 and 3
 */
#[TestDox('Security: malicious context values')]
class MaliciousValueTest extends SecurityTestCase
{
    #[Test]
    #[TestDox('A null context value fails closed with zero rows, never open')]
    public function null_value_fails_closed(): void
    {
        $this->isolateTo(
            ['tenant_id' => null],
            fn() => $this->assertSame(0, Document::query()->count()),
        );
    }

    #[Test]
    #[TestDox('A SQL-injection payload is rejected as a malformed uuid before reaching Postgres')]
    public function sql_payload_is_rejected_before_the_database(): void
    {
        Rls::defineContext(fn(ContextSchema $schema) => $schema->uuid('tenant_id'));

        $this->expectException(InvalidContextValue::class);

        $this->isolateTo(['tenant_id' => "'; drop table documents; --"], fn() => null);
    }

    #[Test]
    #[TestDox('A type-mismatched value (integer for a uuid key) is rejected in PHP')]
    public function type_mismatch_is_rejected(): void
    {
        Rls::defineContext(fn(ContextSchema $schema) => $schema->uuid('tenant_id'));

        $this->expectException(InvalidContextValue::class);

        $this->isolateTo(['tenant_id' => 12345], fn() => null);
    }

    #[Test]
    #[TestDox('An out-of-range integer for an integer key is rejected before the cast can throw')]
    public function overflowing_integer_is_rejected(): void
    {
        Rls::defineContext(fn(ContextSchema $schema) => $schema->integer('region_id'));

        $this->expectException(InvalidContextValue::class);

        // Beyond int4 max: passes a shape-only check but would throw on the
        // ::integer cast in every query.
        $this->isolateTo(['region_id' => 2147483648], fn() => null);
    }

    #[Test]
    #[TestDox('A SQL payload that escapes validation stays a bound parameter and never executes')]
    public function unvalidated_payload_stays_bound(): void
    {
        // With no schema declared, value validation is skipped, so the payload
        // actually reaches set_config. It must arrive as a bound parameter: the
        // uuid cast in the policy fails closed, and the DROP never runs.
        $payload = "x'); drop table documents; --";

        $this->isolateTo(['tenant_id' => $payload], function (): void {
            try {
                // Savepoint so the expected cast failure rolls back cleanly
                // without aborting the surrounding test transaction.
                DB::transaction(fn() => Document::query()->count());
            } catch (QueryException) {
                // The ::uuid cast on a non-uuid context value throws — fail closed.
            }
        });

        $this->assertTrue(
            Schema::hasTable('documents'),
            'The injection payload executed — it was interpolated, not bound.',
        );
    }

    #[Test]
    #[TestDox('A false boolean value serializes to the literal false, not an empty (no-context) GUC')]
    public function false_boolean_does_not_collapse_to_no_context(): void
    {
        Rls::defineContext(fn(ContextSchema $schema) => $schema->boolean('active'));

        // A (string) cast of false is '', which rls.context() reads as NULL —
        // collapsing a real `false` scope into "no context". The GUC must carry
        // the literal 'false' instead.
        $this->isolateTo(['active' => false], function (): void {
            DB::transaction(function (): void {
                $value = $this->selectSingleValueFromDatabase(
                    "select current_setting('app.active', true) as value",
                );

                $this->assertSame('false', $value);
            });
        });
    }
}
