<?php

namespace Radiergummi\LaravelRls\Tests\Feature;

use Radiergummi\LaravelRls\Exceptions\MissingTenantContext;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\Tests\Models\Document;
use Radiergummi\LaravelRls\Tests\TestCase;

class FailLoudGuardTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('rls.on_missing_context', 'throw');
    }

    public function test_query_on_managed_table_without_context_throws(): void
    {
        $this->expectException(MissingTenantContext::class);

        Document::count();
    }

    public function test_query_with_context_does_not_throw(): void
    {
        Rls::actingAs(['tenant_id' => '11111111-1111-1111-1111-111111111111'], function () {
            $this->assertSame(0, Document::count());
        });
    }

    public function test_query_inside_bypass_does_not_throw(): void
    {
        Rls::withoutRls('maintenance', function () {
            $this->assertSame(0, Document::count());
        });
    }
}
