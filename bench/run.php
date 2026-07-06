<?php

declare(strict_types=1);

use Radiergummi\LaravelRls\Bench\BenchmarkEnvironment;
use Radiergummi\LaravelRls\Bench\Boot;
use Radiergummi\LaravelRls\Bench\Endpoint;
use Radiergummi\LaravelRls\Bench\EndpointConfig;
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
use Radiergummi\LaravelRls\Bench\Toxiproxy;
use Radiergummi\LaravelRls\Bench\Variant;
use Radiergummi\LaravelRls\Context\RlsManager;

require __DIR__ . '/../vendor/autoload.php';

$opts = getopt('', [
    'scale::', 'iterations::', 'warmup::', 'json::', 'md::',
    'endpoint-iterations::', 'endpoint-warmup::',
]);
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

// ---- Endpoints phase ---------------------------------------------------------------------------
// Realistic requests run many standalone queries outside one wrapping transaction. Measure the
// endpoint (establish context once, K standalone selects) across the six boundary/strategy configs,
// then sweep injected network latency on the three direct configs.
$endpointIterations = (int) ($opts['endpoint-iterations'] ?? 200);
$endpointWarmup = (int) ($opts['endpoint-warmup'] ?? 20);
$ks = [1, 10, 30];

// The per-query phase drops its tables per scale, so seed 100k here for the endpoint phase.
$tables = $schema->seed('100k');

$pgbouncerAvailable = false;

try {
    $app->make('db')->connection('pgsql_pgbouncer')->getPdo();
    $pgbouncerAvailable = true;
} catch (Throwable) {
    $pgbouncerAvailable = false;
}

$endpoints = [];

foreach (EndpointConfig::matrix() as $cfg) {
    // Omit cells whose backend isn't up (PgBouncer configs when :6432 is unreachable).
    if ($cfg->connectionName === 'pgsql_pgbouncer' && ! $pgbouncerAvailable) {
        continue;
    }

    // Config 6: unsafe by construction — flag, never measure single-client.
    if ($cfg->unsafe) {
        $endpoints[] = [
            'label' => $cfg->label,
            'connection' => $cfg->connectionName,
            'strategy' => $cfg->strategy,
            'boundary' => $cfg->boundaryLabel,
            'k' => 10,
            'status' => 'unsafe',
            'note' => 'session GUC does not survive PgBouncer transaction pooling',
        ];

        continue;
    }

    $app['config']->set('database.default', $cfg->connectionName);
    $app['config']->set('rls.strategy', $cfg->strategy);

    try {
        foreach ($ks as $k) {
            $endpoint = new Endpoint($app, $tables, $k);

            $control = Stats::summarize($runner->measure(
                static fn(Variant $v) => $endpoint->run($cfg, 'control'),
                Variant::Control,
                $endpointWarmup,
                $endpointIterations,
            ))['mean_us'];

            $treatment = Stats::summarize($runner->measure(
                static fn(Variant $v) => $endpoint->run($cfg, 'treatment'),
                Variant::Treatment,
                $endpointWarmup,
                $endpointIterations,
            ))['mean_us'];

            $overhead = $treatment - $control;
            $endpoints[] = [
                'label' => $cfg->label,
                'connection' => $cfg->connectionName,
                'strategy' => $cfg->strategy,
                'boundary' => $cfg->boundaryLabel,
                'k' => $k,
                'status' => 'ok',
                'control_us' => $control,
                'treatment_us' => $treatment,
                'overhead_endpoint_us' => $overhead,
                'overhead_per_query_us' => $overhead / $k,
            ];
        }
    } finally {
        // Reset the session context WHILE rls.strategy is still 'session' (else the reset is a
        // silent no-op and the GUC leaks), THEN restore the default connection + strategy.
        $rls->forget();
        $app->make('db')->connection($cfg->connectionName)->resetSessionContext();
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('rls.strategy', 'transaction');
    }
}

// ---- Latency sweep -----------------------------------------------------------------------------
// Rebind the three direct configs onto pgsql_delayed (port 5433) so queries traverse the Toxiproxy
// proxy carrying the injected latency; gate on a real select 1 through that data path.
$toxiproxy = new Toxiproxy();
$toxiproxyAvailable = false;
$latencySweep = [];
$sweepPoints = [[0, 0], [1, 0], [5, 1]]; // {0, 1ms, 5ms+-1}; Toxiproxy latency/jitter are integer ms

if ($toxiproxy->available()) {
    $toxiproxy->reset('postgres', '0.0.0.0:5433', 'host.docker.internal:5432');

    $dataPathOk = false;

    try {
        $app->make('db')->connection('pgsql_delayed')->select('select 1');
        $dataPathOk = true;
    } catch (Throwable) {
        $dataPathOk = false;
    }

    if ($dataPathOk) {
        $toxiproxyAvailable = true;

        foreach (array_slice(EndpointConfig::matrix(), 0, 3) as $cfg) {
            $app['config']->set('database.default', 'pgsql_delayed');
            $app['config']->set('rls.strategy', $cfg->strategy);

            try {
                foreach ($sweepPoints as [$ms, $jitter]) {
                    $toxiproxy->setLatency('postgres', $ms, $jitter);
                    $endpoint = new Endpoint($app, $tables, 10);

                    $control = Stats::summarize($runner->measure(
                        static fn(Variant $v) => $endpoint->run($cfg, 'control'),
                        Variant::Control,
                        $endpointWarmup,
                        $endpointIterations,
                    ))['mean_us'];

                    $treatment = Stats::summarize($runner->measure(
                        static fn(Variant $v) => $endpoint->run($cfg, 'treatment'),
                        Variant::Treatment,
                        $endpointWarmup,
                        $endpointIterations,
                    ))['mean_us'];

                    $latencySweep[] = [
                        'label' => $cfg->label,
                        'k' => 10,
                        'injected_ms' => $ms,
                        'jitter_ms' => $jitter,
                        'control_us' => $control,
                        'treatment_us' => $treatment,
                        'overhead_endpoint_us' => $treatment - $control,
                    ];
                }
            } finally {
                $rls->forget();
                $app->make('db')->connection('pgsql_delayed')->resetSessionContext();
                $app['config']->set('database.default', 'pgsql');
                $app['config']->set('rls.strategy', 'transaction');
            }
        }

        $toxiproxy->clear('postgres');
    }
}

$schema->drop();

$env = BenchmarkEnvironment::describe(
    (string) $db->selectOne('select version() as v')->v,
    trim((string) shell_exec('git rev-parse --short HEAD')),
    gmdate('Y-m-d\TH:i:s\Z'),
    pgbouncer: $pgbouncerAvailable,
    toxiproxy: $toxiproxyAvailable,
    emulatePrepares: (bool) $db->getPdo()->getAttribute(PDO::ATTR_EMULATE_PREPARES),
);

$json = new JsonReporter();
$document = $json->render(
    $env,
    [
        'iterations' => $iterations,
        'warmup' => $warmup,
        'scales' => $scales,
        'endpoint_iterations' => $endpointIterations,
        'endpoint_warmup' => $endpointWarmup,
    ],
    $cells,
    $amortization,
    $explain,
    $endpoints,
    $latencySweep,
);
$json->write($jsonPath, $document);

$markdown = (new MarkdownReporter())->render($document);

if ($mdPath !== null) {
    file_put_contents($mdPath, $markdown);
}
echo $markdown;
