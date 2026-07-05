<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Radiergummi\LaravelRls\Exceptions\MissingIsolationContext;
use Radiergummi\LaravelRls\Facades\Rls;
use Radiergummi\LaravelRls\Tests\Models\Document;
use Radiergummi\LaravelRls\Tests\TestCase;

class FailLoudGuardTest extends TestCase
{
    #[Test]
    public function query_on_managed_table_without_context_throws(): void
    {
        $this->expectException(MissingIsolationContext::class);

        Document::count();
    }

    #[Test]
    public function query_with_context_does_not_throw(): void
    {
        Rls::isolateTo(['tenant_id' => '11111111-1111-1111-1111-111111111111'], function () {
            $this->assertSame(0, Document::count());
        });
    }

    #[Test]
    public function query_inside_bypass_does_not_throw(): void
    {
        Rls::withoutIsolation('maintenance', function () {
            $this->assertSame(0, Document::count());
        });
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('rls.on_missing_context', 'throw');
    }
}
