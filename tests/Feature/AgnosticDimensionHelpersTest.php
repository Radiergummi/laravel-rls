<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Radiergummi\LaravelRls\Testing\InteractsWithRls;
use Radiergummi\LaravelRls\Tests\TestCase;

/**
 * The isolation assertion helpers must work for any declared dimension, not
 * only tenant_id — here, a table scoped by org_id.
 */
class AgnosticDimensionHelpersTest extends TestCase
{
    use InteractsWithRls;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('org_things', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->scopedBy('org_id');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('org_things');
        parent::tearDown();
    }

    public function test_isolation_helper_works_for_a_non_tenant_dimension(): void
    {
        $a = (string) Str::uuid();
        $b = (string) Str::uuid();

        $this->withoutRls('seed', function () use ($a, $b) {
            DB::table('org_things')->insert(['id' => Str::uuid(), 'org_id' => $a]);
            DB::table('org_things')->insert(['id' => Str::uuid(), 'org_id' => $b]);
        });

        $this->assertRlsIsolates(OrgThing::class, from: $a, cannotSee: $b, dimension: 'org_id');
    }

    public function test_cross_dimension_writes_are_rejected_for_a_non_tenant_dimension(): void
    {
        $a = (string) Str::uuid();
        $b = (string) Str::uuid();

        $this->assertCannotWriteAcrossTenants(OrgThing::class, actingAs: $a, tenant: $b, dimension: 'org_id');
    }
}

class OrgThing extends Model
{
    use HasUuids;

    protected $table = 'org_things';

    protected $guarded = [];

    public $timestamps = false;
}
