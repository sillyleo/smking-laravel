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
    protected $signature = 'smking:doctor {--path=__smking-doctor : Path to probe AEO status for (uses a synthetic path by default to avoid registering real URLs in the audit queue)}';

    protected $description = 'Verify smking SDK install + connectivity';

    public function handle(Application $app, HttpFactory $http, AeoClient $client): int
    {
        $checks = [
            $this->checkConfigPublished($app),
            $this->checkConfigSchemaDrift($app),
            $this->checkApiKey(),
            $this->checkBaseUrl(),
            $this->checkMiddlewareInKernel($app),
            $this->checkApiReachable($http),
            $this->checkPathStatus($client, (string) $this->option('path')),
            ...$this->checkTakeoverFlags(),
            ...$this->checkTakeoverSaasEndpoints($http),
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
    private function checkConfigPublished(Application $app): array
    {
        $path = $app->configPath('smking.php');

        // mergeConfigFrom in the service provider already loads sensible
        // defaults — publishing is only needed when the host wants to edit
        // only/except patterns or inject.* flags. So this is informational,
        // never a hard fail.
        return file_exists($path)
            ? ['status' => 'pass', 'label' => 'config/smking.php published', 'detail' => $path]
            : ['status' => 'info', 'label' => 'config/smking.php published', 'detail' => 'optional — defaults from package are merged automatically. publish only if you need to edit only/except/inject.* in your repo.'];
    }

    /**
     * v0.6.3: surface keys present in the package default config but missing
     * from the customer's published `config/smking.php`. mergeConfigFrom in
     * the service provider already overlays defaults at runtime, so missing
     * keys aren't a runtime bug — but the customer's published file shows
     * stale schema after a package upgrade, hiding new knobs they may want
     * to set.
     *
     * @return array{status: 'pass'|'fail'|'info', label: string, detail: string}
     */
    private function checkConfigSchemaDrift(Application $app): array
    {
        $userPath = $app->configPath('smking.php');
        if (! file_exists($userPath)) {
            return ['status' => 'info', 'label' => 'Config schema drift', 'detail' => 'config not published — drift check skipped'];
        }

        $packagePath = __DIR__.'/../../config/smking.php';
        if (! file_exists($packagePath)) {
            return ['status' => 'info', 'label' => 'Config schema drift', 'detail' => 'package config not found at '.$packagePath];
        }

        $user = require $userPath;
        $package = require $packagePath;

        if (! is_array($user) || ! is_array($package)) {
            return ['status' => 'info', 'label' => 'Config schema drift', 'detail' => 'config files did not return arrays'];
        }

        $missing = $this->collectMissingKeys($package, $user);

        if (count($missing) === 0) {
            return ['status' => 'pass', 'label' => 'Config schema drift', 'detail' => 'in sync with package defaults'];
        }

        return [
            'status' => 'info',
            'label' => 'Config schema drift',
            'detail' => count($missing).' new key(s) since last publish: '.implode(', ', $missing).'. Re-publish with `php artisan vendor:publish --tag=smking-config --force` (overwrites your file) or copy manually.',
        ];
    }

    /**
     * Recursively diff package keys against user keys; nested associative
     * arrays recurse into their children, list-style arrays (e.g. `except`)
     * compare at the top level only — ordering / extending those is the
     * customer's call.
     *
     * @param  array<int|string, mixed>  $package
     * @param  array<int|string, mixed>  $user
     * @return list<string>
     */
    private function collectMissingKeys(array $package, array $user, string $prefix = ''): array
    {
        $missing = [];
        foreach ($package as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            $fullKey = $prefix === '' ? $key : "{$prefix}.{$key}";

            if (! array_key_exists($key, $user)) {
                $missing[] = $fullKey;

                continue;
            }

            if (is_array($value) && is_array($user[$key]) && $this->isAssociative($value)) {
                $missing = array_merge($missing, $this->collectMissingKeys($value, $user[$key], $fullKey));
            }
        }

        return $missing;
    }

    /**
     * @param  array<int|string, mixed>  $arr
     */
    private function isAssociative(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }

        return array_keys($arr) !== range(0, count($arr) - 1);
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

        // Probe the actual AEO endpoint rather than the base URL root. Hitting
        // the root passes for *any* live host (typo'd domain, google.com, a
        // dev landing page) — useless as a reachability signal. The endpoint
        // rejects empty bodies with 400/422 (validation) or 401 (no key);
        // either confirms the route exists and our server-side handler is
        // mounted. 404 here means the SMKING_BASE_URL points somewhere that
        // isn't a smking deployment.
        $endpoint = rtrim($url, '/').'/api/v1/public/aeo';
        try {
            $response = $http->timeout(2)->acceptJson()->asJson()->post($endpoint, []);
        } catch (Throwable $e) {
            return ['status' => 'fail', 'label' => 'API reachable', 'detail' => 'connection failed: '.$e->getMessage()];
        }

        $status = $response->status();

        if (in_array($status, [400, 401, 422], true)) {
            return ['status' => 'pass', 'label' => 'API reachable', 'detail' => "{$endpoint} → HTTP {$status} (endpoint exists)"];
        }

        if ($status === 404) {
            return ['status' => 'fail', 'label' => 'API reachable', 'detail' => "404 from {$endpoint} — base_url likely points at the wrong host"];
        }

        if ($status >= 500) {
            return ['status' => 'fail', 'label' => 'API reachable', 'detail' => "upstream {$status} from {$endpoint}"];
        }

        // 200 / 2xx / 3xx with empty body shouldn't happen — it means the
        // server accepted an invalid request without validation, which we
        // surface so the customer can investigate.
        return ['status' => 'fail', 'label' => 'API reachable', 'detail' => "unexpected HTTP {$status} from {$endpoint} (expected 400/401/422)"];
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
     * Path-takeover config flags. v0.9.0+ — middleware auto-serves
     * /sitemap.xml, /robots.txt, /llms.txt when the customer's app would
     * 404 on them. Each flag is info-only (false is a valid choice for
     * customers who want to keep their own routing), but we surface it
     * so the operator can confirm the state matches their expectation
     * without grepping `config/smking.php` by hand.
     *
     * @return list<array{status: 'pass'|'fail'|'info', label: string, detail: string}>
     */
    private function checkTakeoverFlags(): array
    {
        /** @var array<string, mixed> $flags */
        $flags = (array) config('smking.takeover', []);
        $kinds = [
            'sitemap' => '/sitemap.xml',
            'robots' => '/robots.txt',
            'llms_txt' => '/llms.txt',
        ];

        $checks = [];
        foreach ($kinds as $key => $path) {
            // Default ON (per config/smking.php defaults) — only false is
            // an explicit opt-out we surface differently.
            $enabled = ! array_key_exists($key, $flags) || $flags[$key] !== false;
            $checks[] = [
                'status' => 'info',
                'label' => "Takeover {$path}",
                'detail' => $enabled
                    ? 'enabled — middleware will serve when your app returns 404'
                    : 'disabled (config/smking.php takeover.'.$key.' = false)',
            ];
        }

        return $checks;
    }

    /**
     * Live probe of the three takeover SaaS endpoints. Confirms the
     * server-side content is reachable + non-empty before the middleware
     * tries to fall back to it. Skipped when api_key or base_url is
     * missing (those are checked above; rerun doctor once they're set).
     *
     * @return list<array{status: 'pass'|'fail'|'info', label: string, detail: string}>
     */
    private function checkTakeoverSaasEndpoints(HttpFactory $http): array
    {
        $apiKey = (string) config('smking.api_key', '');
        $baseUrl = (string) config('smking.base_url', '');

        if ($apiKey === '' || $baseUrl === '') {
            return [[
                'status' => 'info',
                'label' => 'Takeover SaaS endpoints',
                'detail' => 'skipped — set SMKING_API_KEY and SMKING_BASE_URL first',
            ]];
        }

        $endpoints = [
            'sitemap' => '/api/v1/public/sitemap.xml',
            'robots' => '/api/v1/public/robots.txt',
            'llms_txt' => '/api/v1/public/llms-txt',
        ];

        $checks = [];
        foreach ($endpoints as $kind => $path) {
            $url = rtrim($baseUrl, '/').$path;
            try {
                $response = $http->timeout(3)->get($url, ['key' => $apiKey]);
            } catch (Throwable $e) {
                $checks[] = [
                    'status' => 'fail',
                    'label' => "Takeover endpoint {$kind}",
                    'detail' => 'connection failed: '.$e->getMessage(),
                ];

                continue;
            }

            $status = $response->status();
            if ($status >= 200 && $status < 300) {
                $bytes = strlen((string) $response->body());
                $checks[] = [
                    'status' => 'pass',
                    'label' => "Takeover endpoint {$kind}",
                    'detail' => "HTTP {$status} · {$bytes} bytes from {$path}",
                ];
            } elseif ($status === 401) {
                $checks[] = [
                    'status' => 'fail',
                    'label' => "Takeover endpoint {$kind}",
                    'detail' => "HTTP 401 from {$path} — SMKING_API_KEY rejected",
                ];
            } else {
                $checks[] = [
                    'status' => 'fail',
                    'label' => "Takeover endpoint {$kind}",
                    'detail' => "HTTP {$status} from {$path}",
                ];
            }
        }

        return $checks;
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
