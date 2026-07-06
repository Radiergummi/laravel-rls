<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Radiergummi\LaravelRls\Testing\InteractsWithRls;
use Radiergummi\LaravelRls\Tests\TestCase;

#[TestDox('Isolation Key Agnostic')]
class IsolationKeyAgnosticTest extends TestCase
{
    use InteractsWithRls;

    #[Test]
    #[TestDox('assertIsolates() works for a non-tenant isolation key')]
    public function isolation_helper_works_for_a_non_tenant_key(): void
    {
        $a = (string) Str::uuid();
        $b = (string) Str::uuid();

        // WITH CHECK permits same-key writes, so seed each row within its own context.
        $this->isolateTo(
            ['org_id' => $a],
            fn() => DB::table('org_things')->insert(['id' => Str::uuid(), 'org_id' => $a]),
        );
        $this->isolateTo(
            ['org_id' => $b],
            fn() => DB::table('org_things')->insert(['id' => Str::uuid(), 'org_id' => $b]),
        );

        $this->assertIsolates(OrgThing::class, isolatedBy: 'org_id', acting: $a, cannotSee: $b);
    }

    #[Test]
    #[TestDox('assertRejectsForeignWrite() rejects cross-key writes for a non-tenant isolation key')]
    public function cross_key_writes_are_rejected_for_a_non_tenant_key(): void
    {
        $a = (string) Str::uuid();
        $b = (string) Str::uuid();

        $this->assertRejectsForeignWrite(OrgThing::class, isolatedBy: 'org_id', acting: $a, foreign: $b);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('org_things', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->isolatedBy('org_id');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('org_things');
        parent::tearDown();
    }
}

class OrgThing extends Model
{
    use HasUuids;

    public $timestamps = false;
    protected $table = 'org_things';
    protected $guarded = [];
}
