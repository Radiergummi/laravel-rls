<?php

declare(strict_types=1);

use Radiergummi\LaravelRls\Bench\BenchmarkEnvironment;
use Radiergummi\LaravelRls\Bench\Boot;
use Radiergummi\LaravelRls\Bench\ExplainProbe;
use Radiergummi\LaravelRls\Bench\Report\JsonReporter;
use Radiergummi\LaravelRls\Bench\Report\MarkdownReporter;
use Radiergummi\LaravelRls\Bench\Runner;
use Radiergummi\LaravelRls\Bench\Scenario\Aggregate;
use Radiergummi\LaravelRls\Bench\Scenario\Insert;
use Radiergummi\LaravelRls\Bench\Scenario\PointSelect;
use Radiergummi\LaravelRls\Bench\Scenario\RangeScan;
use Radiergummi\LaravelRls\Bench\Schema;
use Radiergummi\LaravelRls\Bench\Stats;
use Radiergummi\LaravelRls\Bench\TableSet;
use Radiergummi\LaravelRls\Bench\Variant;
use Radiergummi\LaravelRls\Context\RlsManager;

require __DIR__ . '/../vendor/autoload.php';

$opts = getopt('', ['scale::', 'iterations::', 'warmup::', 'json::', 'md::']);
$scales = explode(',', $opts['scale'] ?? '1k,100k');
$iterations = (int) ($opts['iterations'] ?? 2000);
$warmup = (int) ($opts['warmup'] ?? 200);
$jsonPath = $opts['json'] ?? __DIR__ . '/baseline.json';
$mdPath = $opts['md'] ?? null;

$app = Boot::app();
$schema = new Schema($app);
$runner = new Runner();
$db = $app->make('db')->connection();
$rls = $app->make(RlsManager::class);

$cells = [];
$explain = [];
$amortization = [];

foreach ($scales as $scale) {
    $tables = $schema->seed($scale);

    $scenarios = [
        new PointSelect($app, $tables),
        new RangeScan($app, $tables),
        new Aggregate($app, $tables),
        new Insert($app, $tables),
    ];

    foreach ($scenarios as $scenario) {
        foreach (Variant::cases() as $variant) {
            $samples = $runner->measure(
                static fn(Variant $v) => $scenario->run($v),
                $variant,
                $warmup,
                $iterations,
            );
            $cells[] = [
                'scenario' => $scenario->name(),
                'variant' => $variant->value,
                'scale' => $scale,
                ...Stats::summarize($samples),
            ];
        }

        $target = $scenario->explainTarget();

        if ($target !== null) {
            $probe = $rls->isolateTo(
                ['tenant_id' => $target['tenant']],
                static fn() => ExplainProbe::probe($db, $target['sql'], $target['bindings']),
            );
            $explain[] = ['scenario' => $scenario->name(), 'scale' => $scale, ...$probe];
        }
    }

    // Amortization probe: fixed per-transaction set_config cost = single-query-txn latency minus
    // the per-query cost inside a batched transaction. The single-query figure is the point_select
    // treatment read already measured above, so we reuse its mean rather than re-measuring it.
    $queriesPerTxn = 10;
    $one = 0.0;

    foreach ($cells as $cell) {
        if ($cell['scenario'] === 'point_select' && $cell['variant'] === 'treatment' && $cell['scale'] === $scale) {
            $one = $cell['mean_us'];

            break;
        }
    }

    $tenPerTxn = Stats::summarize(
        $runner->measure(
            static function (Variant $v) use ($rls, $db, $tables, $queriesPerTxn): void {
                $rls->isolateTo(
                    ['tenant_id' => $tables->probeTenantId],
                    static function () use ($db, $tables, $queriesPerTxn): void {
                        $db->transaction(static function () use ($db, $tables, $queriesPerTxn): void {
                            for ($q = 0; $q < $queriesPerTxn; $q++) {
                                $db->select(
                                    'select * from ' . TableSet::TREATMENT . ' where id = ?',
                                    [$tables->probeRowId],
                                );
                            }
                        });
                    },
                );
            },
            Variant::Treatment,
            $warmup,
            $iterations,
        ),
    )['mean_us'] / $queriesPerTxn;
    $amortization[] = [
        'scale' => $scale,
        'per_txn_1_query_us' => $one,
        'per_txn_10_query_us' => $tenPerTxn,
        'derived_fixed_setconfig_us' => max(0.0, $one - $tenPerTxn),
    ];

    $schema->drop();
}

$env = BenchmarkEnvironment::describe(
    (string) $db->selectOne('select version() as v')->v,
    trim((string) shell_exec('git rev-parse --short HEAD')),
    gmdate('Y-m-d\TH:i:s\Z'),
    pgbouncer: false,
    emulatePrepares: (bool) $db->getPdo()->getAttribute(PDO::ATTR_EMULATE_PREPARES),
);

$json = new JsonReporter();
$document = $json->render(
    $env,
    ['iterations' => $iterations, 'warmup' => $warmup, 'scales' => $scales],
    $cells,
    $amortization,
    $explain,
);
$json->write($jsonPath, $document);

$markdown = (new MarkdownReporter())->render($document);

if ($mdPath !== null) {
    file_put_contents($mdPath, $markdown);
}
echo $markdown;
