<?php

declare(strict_types=1);

namespace Radiergummi\LaravelRls\Tests\Feature\Bench;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Radiergummi\LaravelRls\Bench\Boot;
use Radiergummi\LaravelRls\Bench\Scenario\Aggregate;
use Radiergummi\LaravelRls\Bench\Scenario\Insert;
use Radiergummi\LaravelRls\Bench\Scenario\PointSelect;
use Radiergummi\LaravelRls\Bench\Scenario\RangeScan;
use Radiergummi\LaravelRls\Bench\Schema;
use Radiergummi\LaravelRls\Bench\TableSet;
use Radiergummi\LaravelRls\Bench\Variant;

#[TestDox('Bench Scenario')]
class ScenarioTest extends TestCase
{
    #[Test]
    #[TestDox('Every scenario runs each variant without error; treatment and control agree on counts')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function scenarios_run_all_variants(): void
    {
        $app = Boot::app();
        $schema = new Schema($app);

        try {
            $tables = $schema->seed('1k');
            $admin = $app->make('db')->connection('pgsql_admin');

            foreach ([PointSelect::class, RangeScan::class, Aggregate::class, Insert::class] as $class) {
                $scenario = new $class($app, $tables);

                foreach (Variant::cases() as $variant) {
                    // Must not throw for any variant.
                    $scenario->run($variant);
                }
                $this->assertNotSame('', $scenario->name());
            }

            // Both counts are captured after the loop, since Insert's Control and Treatment
            // variants each add one probe-tenant row during the loop above.
            $controlCount = (int) $admin->table(TableSet::CONTROL)
                ->where('tenant_id', $tables->probeTenantId)->count();
            $treatmentCount = $app->make('rls')->isolateTo(
                ['tenant_id' => $tables->probeTenantId],
                static fn() => (int) $app->make('db')->connection()->table(TableSet::TREATMENT)->count(),
            );

            $this->assertGreaterThan(0, $controlCount);
            $this->assertSame($controlCount, $treatmentCount);
        } finally {
            $schema->drop();
        }
    }
}
