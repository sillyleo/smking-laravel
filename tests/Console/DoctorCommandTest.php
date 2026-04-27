<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests\Console;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Smking\Laravel\Tests\TestCase;

class DoctorCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // v0.2.1: service provider now pushes middleware unconditionally
        // (including in console env), so we no longer need to push manually
        // here — testbench's HTTP kernel already has InjectAeo registered.

        // Testbench doesn't run vendor:publish; touch the config file so
        // 'config published' check passes.
        $configPath = config_path('smking.php');
        @mkdir(dirname($configPath), 0755, true);
        if (! file_exists($configPath)) {
            file_put_contents($configPath, '<?php return [];');
        }
    }

    protected function tearDown(): void
    {
        $configPath = config_path('smking.php');
        if (file_exists($configPath)) {
            @unlink($configPath);
        }

        parent::tearDown();
    }

    public function test_returns_zero_when_all_checks_pass(): void
    {
        // POST to /api/v1/public/aeo with empty body → 400 (validation
        // failure) is the canonical "endpoint exists" signal post-v0.2.3.
        Http::fake([
            'api.test/api/v1/public/aeo' => Http::response(['error' => 'missing key'], 400),
        ]);

        $exit = $this->artisan('smking:doctor', ['--path' => '/'])->run();

        $this->assertSame(0, $exit);
    }

    public function test_returns_one_when_api_unreachable(): void
    {
        Http::fake([
            'api.test/api/v1/public/aeo' => fn () => throw new ConnectionException('cannot reach host'),
        ]);

        $exit = $this->artisan('smking:doctor')->run();

        $this->assertSame(1, $exit);
    }

    public function test_returns_one_when_api_endpoint_returns_404(): void
    {
        // base_url points at a host that doesn't have the smking API mounted
        // (typo'd domain, accidentally pointing at a different deployment).
        // v0.2.2 doctor would have called this ✅ because it only checked
        // `< 500` on the base_url root. v0.2.3 catches it.
        Http::fake([
            'api.test/api/v1/public/aeo' => Http::response('Not Found', 404),
        ]);

        $exit = $this->artisan('smking:doctor')->run();

        $this->assertSame(1, $exit);
    }

    public function test_returns_one_when_api_key_missing(): void
    {
        config()->set('smking.api_key', null);
        Http::fake([
            'api.test/api/v1/public/aeo' => Http::response([], 400),
        ]);

        $exit = $this->artisan('smking:doctor')->run();

        $this->assertSame(1, $exit);
    }

    public function test_returns_one_when_api_key_wrong_prefix(): void
    {
        config()->set('smking.api_key', 'sk_should_be_pk');
        Http::fake([
            'api.test/api/v1/public/aeo' => Http::response([], 400),
        ]);

        $exit = $this->artisan('smking:doctor')->run();

        $this->assertSame(1, $exit);
    }

    public function test_returns_zero_when_config_not_published(): void
    {
        // v0.2.3 fix: config publishing is optional (mergeConfigFrom loads
        // defaults), so doctor should NOT fail when config/smking.php is
        // absent. v0.2.2 wrongly failed here.
        $configPath = config_path('smking.php');
        if (file_exists($configPath)) {
            @unlink($configPath);
        }

        Http::fake([
            'api.test/api/v1/public/aeo' => Http::response([], 400),
        ]);

        $exit = $this->artisan('smking:doctor')->run();

        $this->assertSame(0, $exit);
    }
}
