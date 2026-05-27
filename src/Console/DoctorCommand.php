<?php

declare(strict_types=1);

namespace Smking\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Kernel as FoundationKernel;
use Illuminate\Http\Client\Factory as HttpFactory;
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
    protected $signature = 'smking:doctor {--json : Output structured JSON instead of the pretty-printed report (used by the @smking/wizard install agent)}';

    protected $description = 'Verify smking SDK install + connectivity';

    public function handle(Application $app, HttpFactory $http): int
    {
        $checks = [
            $this->checkConfigPublished($app),
            $this->checkConfigSchemaDrift($app),
            $this->checkApiKey(),
            $this->checkBaseUrl(),
            $this->checkMiddlewareInKernel($app),
            $this->checkApiReachable($http),
        ];

        $hasFailure = false;
        foreach ($checks as $check) {
            if ($check['status'] === 'fail') {
                $hasFailure = true;
                break;
            }
        }

        // JSON mode: skip pretty rendering entirely so stdout is parseable
        // by the @smking/wizard install agent. Caller does JSON.parse on the
        // full stdout.
        if ($this->option('json')) {
            $this->renderJson($checks, $hasFailure);

            return $hasFailure ? self::FAILURE : self::SUCCESS;
        }

        foreach ($checks as $check) {
            $this->renderCheck($check);
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
     * Emit structured JSON for machine consumption. Used by the smking
     * install wizard's `run_doctor` MCP tool to parse check results
     * directly rather than scraping the pretty-printed output.
     *
     * Stable shape (do not break without bumping wizard version): each
     * check keeps the same {status, label, detail} keys the pretty render
     * already uses, plus a summary block with counts and an `ok` bool so
     * the agent can short-circuit on the boolean.
     *
     * @param  list<array{status: 'pass'|'fail'|'info', label: string, detail: string}>  $checks
     */
    private function renderJson(array $checks, bool $hasFailure): void
    {
        $summary = [
            'passed' => count(array_filter($checks, fn (array $c): bool => $c['status'] === 'pass')),
            'failed' => count(array_filter($checks, fn (array $c): bool => $c['status'] === 'fail')),
            'info' => count(array_filter($checks, fn (array $c): bool => $c['status'] === 'info')),
            'ok' => ! $hasFailure,
        ];

        $payload = [
            'checks' => $checks,
            'summary' => $summary,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            // Defensive fallback: invalid UTF-8 in a customer's exception
            // message could trip json_encode. Emit a minimal valid payload
            // so the wizard agent gets something parseable rather than
            // silent empty stdout.
            $json = '{"checks":[],"summary":{"passed":0,"failed":0,"info":0,"ok":false},"error":"json_encode_failed"}';
        }

        $this->output->writeln($json);
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
