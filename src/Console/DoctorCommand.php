<?php

declare(strict_types=1);

namespace Smking\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Kernel as FoundationKernel;
use Illuminate\Http\Client\Factory as HttpFactory;
use Smking\Laravel\AeoClient;
use ReflectionClass;
use ReflectionException;
use Smking\Laravel\Http\Middleware\InjectAeo;
use Smking\Laravel\SmkingServiceProvider;
use Throwable;

/**
 * `php artisan smking:doctor` — install verification.
 *
 * Walks the install checklist (provider, config, env, middleware, API
 * connectivity) and prints a green/red report. Exits 0 when every
 * required check passes, 1 otherwise. Designed so a freshly-installed
 * package can be verified in 30 seconds without depending on the smking
 * backend having audited any of the host's URLs.
 */
class DoctorCommand extends Command
{
    protected $signature = 'smking:doctor {--path=/ : Path to probe AEO status for}';

    protected $description = 'Verify smking SDK install + connectivity';

    public function handle(Application $app, HttpFactory $http, AeoClient $client): int
    {
        $checks = [
            $this->checkProviderRegistered($app),
            $this->checkConfigPublished($app),
            $this->checkApiKey(),
            $this->checkBaseUrl(),
            $this->checkMiddlewareInKernel($app),
            $this->checkApiReachable($http),
            $this->checkPathStatus($client, (string) $this->option('path')),
        ];

        foreach ($checks as $check) {
            $this->renderCheck($check);
        }

        $hasFailure = false;
        foreach ($checks as $check) {
            if ($check['status'] === 'fail') {
                $hasFailure = true;
                break;
            }
        }

        $this->newLine();
        if ($hasFailure) {
            $this->line('<error>smking: install incomplete — fix the ❌ items above.</error>');

            return self::FAILURE;
        }

        $this->line('<info>smking: install OK.</info>');

        return self::SUCCESS;
    }

    /**
     * @return array{status: 'pass'|'fail'|'info', label: string, detail: string}
     */
    private function checkProviderRegistered(Application $app): array
    {
        $providers = $app->getLoadedProviders();

        return array_key_exists(SmkingServiceProvider::class, $providers)
            ? ['status' => 'pass', 'label' => 'ServiceProvider registered', 'detail' => SmkingServiceProvider::class]
            : ['status' => 'fail', 'label' => 'ServiceProvider registered', 'detail' => 'auto-discover failed; run `composer dump-autoload`'];
    }

    /**
     * @return array{status: 'pass'|'fail'|'info', label: string, detail: string}
     */
    private function checkConfigPublished(Application $app): array
    {
        $path = $app->configPath('smking.php');

        return file_exists($path)
            ? ['status' => 'pass', 'label' => 'config/smking.php published', 'detail' => $path]
            : ['status' => 'fail', 'label' => 'config/smking.php published', 'detail' => 'run `php artisan vendor:publish --tag=smking-config`'];
    }

    /**
     * @return array{status: 'pass'|'fail'|'info', label: string, detail: string}
     */
    private function checkApiKey(): array
    {
        $key = config('smking.api_key');

        if (! is_string($key) || $key === '') {
            return ['status' => 'fail', 'label' => 'SMKING_API_KEY set', 'detail' => 'not configured; add SMKING_API_KEY=pk_... to your .env'];
        }

        if (! str_starts_with($key, 'pk_')) {
            return ['status' => 'fail', 'label' => 'SMKING_API_KEY set', 'detail' => 'value must start with `pk_` (publishable key from dashboard)'];
        }

        return ['status' => 'pass', 'label' => 'SMKING_API_KEY set', 'detail' => substr($key, 0, 8).'…'];
    }

    /**
     * @return array{status: 'pass'|'fail'|'info', label: string, detail: string}
     */
    private function checkBaseUrl(): array
    {
        $url = config('smking.base_url');

        if (! is_string($url) || $url === '') {
            return ['status' => 'fail', 'label' => 'SMKING_BASE_URL set', 'detail' => 'not configured; add SMKING_BASE_URL=https://... to your .env'];
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return ['status' => 'fail', 'label' => 'SMKING_BASE_URL set', 'detail' => "value is not a valid URL: {$url}"];
        }

        return ['status' => 'pass', 'label' => 'SMKING_BASE_URL set', 'detail' => $url];
    }

    /**
     * @return array{status: 'pass'|'fail'|'info', label: string, detail: string}
     */
    private function checkMiddlewareInKernel(Application $app): array
    {
        $kernel = $app->make(HttpKernel::class);

        if (! $kernel instanceof FoundationKernel) {
            return ['status' => 'info', 'label' => 'Middleware in HTTP kernel', 'detail' => 'custom HTTP kernel — skipping reflective check'];
        }

        // Foundation\Http\Kernel exposes $middleware as protected; reflection
        // is the only way to inspect it without rebuilding the request cycle.
        try {
            $reflection = new ReflectionClass($kernel);
            $property = $reflection->getProperty('middleware');
            $property->setAccessible(true);
            /** @var array<int, class-string> $middleware */
            $middleware = (array) $property->getValue($kernel);
        } catch (ReflectionException $e) {
            return ['status' => 'info', 'label' => 'Middleware in HTTP kernel', 'detail' => 'reflection failed: '.$e->getMessage()];
        }

        return in_array(InjectAeo::class, $middleware, true)
            ? ['status' => 'pass', 'label' => 'Middleware in HTTP kernel', 'detail' => InjectAeo::class]
            : ['status' => 'fail', 'label' => 'Middleware in HTTP kernel', 'detail' => 'InjectAeo not registered; check service provider auto-discovery'];
    }

    /**
     * @return array{status: 'pass'|'fail'|'info', label: string, detail: string}
     */
    private function checkApiReachable(HttpFactory $http): array
    {
        $url = config('smking.base_url');
        if (! is_string($url) || $url === '') {
            return ['status' => 'info', 'label' => 'API reachable', 'detail' => 'skipped — base_url not set'];
        }

        try {
            $response = $http->timeout(2)->get($url);
        } catch (Throwable $e) {
            return ['status' => 'fail', 'label' => 'API reachable', 'detail' => 'connection failed: '.$e->getMessage()];
        }

        $status = $response->status();

        return $status < 500
            ? ['status' => 'pass', 'label' => 'API reachable', 'detail' => "{$url} → HTTP {$status}"]
            : ['status' => 'fail', 'label' => 'API reachable', 'detail' => "upstream {$status} from {$url}"];
    }

    /**
     * @return array{status: 'pass'|'fail'|'info', label: string, detail: string}
     */
    private function checkPathStatus(AeoClient $client, string $path): array
    {
        try {
            $aeo = $client->forPath($path === '' ? '/' : $path);
        } catch (Throwable $e) {
            return ['status' => 'info', 'label' => "Path {$path} status", 'detail' => 'probe failed: '.$e->getMessage()];
        }

        return ['status' => 'info', 'label' => "Path {$path} status", 'detail' => $aeo->status];
    }

    /**
     * @param  array{status: 'pass'|'fail'|'info', label: string, detail: string}  $check
     */
    private function renderCheck(array $check): void
    {
        $icon = match ($check['status']) {
            'pass' => '✅',
            'fail' => '❌',
            default => 'ℹ️ ',
        };

        $tag = match ($check['status']) {
            'pass' => '<info>',
            'fail' => '<error>',
            default => '<comment>',
        };

        $closeTag = match ($check['status']) {
            'pass' => '</info>',
            'fail' => '</error>',
            default => '</comment>',
        };

        $this->line("{$icon} {$tag}{$check['label']}{$closeTag} — {$check['detail']}");
    }
}
