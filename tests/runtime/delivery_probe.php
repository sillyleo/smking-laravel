<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Orchestra\Testbench\Foundation\Application as TestbenchApplication;
use Smking\Laravel\CmsClient;
use Smking\Laravel\Data\CmsPage;
use Smking\Laravel\Delivery\DeliveryReportOutbox;
use Smking\Laravel\Delivery\OnDemandDelivery;
use Smking\Laravel\Http\Middleware\TrackCrawlerHit;
use Smking\Laravel\SmkingServiceProvider;
use Symfony\Component\HttpFoundation\Response;

$vendor = getenv('SMKING_RUNTIME_VENDOR');
$vendor = is_string($vendor) && $vendor !== '' ? rtrim($vendor, '/') : dirname(__DIR__, 2).'/vendor';
$loader = require $vendor.'/autoload.php';
$package = getenv('SMKING_RUNTIME_PACKAGE');
if (is_string($package) && $package !== '') {
    if (! $loader instanceof Composer\Autoload\ClassLoader || ! is_dir($package.'/src')) {
        throw new RuntimeException('invalid_runtime_package');
    }
    $loader->setPsr4('Smking\\Laravel\\', [rtrim($package, '/').'/src']);
}

function runtimeEnvironment(string $name): string
{
    $value = getenv($name);
    if (! is_string($value) || $value === '') {
        throw new RuntimeException('missing_'.$name);
    }

    return $value;
}

function runtimeApplication(string $mode)
{
    $app = TestbenchApplication::create(
        options: [
            'load_environment_variables' => false,
            'extra' => [
                'dont-discover' => ['*'],
                'providers' => [SmkingServiceProvider::class],
            ],
        ],
    );

    /** @var ConfigRepository $config */
    $config = $app->make(ConfigRepository::class);
    $redis = [
        'host' => '127.0.0.1',
        'port' => (int) runtimeEnvironment('SMKING_RUNTIME_REDIS_PORT'),
        'database' => 0,
        'timeout' => 0.2,
        'read_timeout' => 0.2,
        'retry_interval' => 0,
        'max_retries' => 0,
    ];
    $config->set('app.env', 'testing');
    $config->set('app.url', 'http://shop.runtime.test');
    $config->set('cache.default', 'delivery_runtime');
    $config->set('cache.stores.delivery_runtime', [
        'driver' => 'redis',
        'connection' => 'default',
        'lock_connection' => 'default',
    ]);
    $config->set('database.redis.client', 'phpredis');
    $config->set('database.redis.options', [
        'cluster' => 'redis',
        'prefix' => 'smking_delivery_runtime_',
        'persistent' => false,
    ]);
    $config->set('database.redis.default', $redis);
    $config->set('smking.api_key', 'pk_runtime');
    $config->set('smking.base_url', 'http://127.0.0.1:'.runtimeEnvironment('SMKING_RUNTIME_ORIGIN_PORT'));
    $config->set('smking.webhook_secret', 'runtime-secret');
    $config->set('smking.cache.enabled', true);
    $config->set('smking.cache.store', 'delivery_runtime');
    $config->set('smking.delivery.mode', $mode);
    $config->set('smking.delivery.aeo_enabled', false);
    $config->set('smking.delivery.capacity', 2);
    $config->set('smking.delivery.work_max_jobs', 4);
    $config->set('smking.delivery.work_budget_ms', 3000);
    $config->set('smking.delivery.heartbeat_seconds', 180);
    $config->set('smking.delivery.notifications_enabled', true);
    $config->set('smking.delivery.notifications_scope', str_repeat('d', 64));
    $config->set('smking.inject_in_tests', true);
    $config->set('smking.track_crawler_hits', true);

    return $app;
}

function runtimeJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit($status < 400 ? 0 : 1);
}

try {
    $action = PHP_SAPI === 'cli'
        ? ($argv[1] ?? '')
        : (is_string($_GET['action'] ?? null) ? $_GET['action'] : '');
    $mode = $action === 'legacy-read' || $action === 'legacy-seed' ? 'legacy' : 'on_demand';
    $app = runtimeApplication($mode);

    if ($action === 'legacy-seed') {
        $page = $app->make(CmsClient::class)->forSlug('article');
        runtimeJson(['status' => $page->status, 'body' => $page->bodyHtml]);
    }

    if ($action === 'prepare') {
        $config = $app->make(ConfigRepository::class);
        $config->set('smking.delivery.mode', 'legacy');
        $kernel = $app->make(ConsoleKernel::class);
        $work = $kernel->call('smking:delivery:work', ['--prepare' => true]);
        $report = $kernel->call('smking:delivery:report', ['--prepare' => true]);
        $prewarm = $kernel->call('smking:delivery:prewarm', ['--slug' => ['article']]);
        $summary = json_decode(trim($kernel->output()), true, flags: JSON_THROW_ON_ERROR);
        runtimeJson([
            'work' => $work,
            'report' => $report,
            'prewarm' => $prewarm,
            'summary' => $summary,
        ], $work === 0 && $report === 0 && $prewarm === 0 ? 200 : 503);
    }

    if ($action === 'read' || $action === 'legacy-read') {
        $page = $app->make(CmsClient::class)->forSlug('article');
        $status = match ($page->status) {
            CmsPage::STATUS_READY => 200,
            CmsPage::STATUS_NOT_FOUND => 404,
            default => 503,
        };
        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');
        echo $page->isReady() ? $page->bodyHtml : 'Service unavailable';
        exit($status < 500 ? 0 : 1);
    }

    if ($action === 'track') {
        $request = Request::create('/products/runtime', 'GET', server: [
            'HTTP_HOST' => 'shop.runtime.test',
            'HTTP_USER_AGENT' => 'GPTBot/1.0',
        ]);
        $app->make(TrackCrawlerHit::class)->terminate($request, new Response('', 200));
        $status = $app->make(DeliveryReportOutbox::class)->status();
        runtimeJson(['pending' => $status['pending_events'] ?? null]);
    }

    if ($action === 'identity') {
        $source = (new ReflectionClass(OnDemandDelivery::class))->getFileName();
        if (! is_string($source)) {
            throw new RuntimeException('runtime_source_unavailable');
        }
        runtimeJson([
            'source' => realpath($source),
            'sha256' => hash_file('sha256', $source),
        ]);
    }

    runtimeJson(['error' => 'invalid_action'], 400);
} catch (Throwable $error) {
    runtimeJson(['error' => 'runtime_probe_failed', 'type' => get_class($error)], 503);
}
