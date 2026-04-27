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
        Http::fake([
            'api.test/api/v1/public/aeo' => Http::response(['status' => 'ready'], 200),
            'api.test' => Http::response('ok', 200),
        ]);

        $exit = $this->artisan('smking:doctor', ['--path' => '/'])->run();

        $this->assertSame(0, $exit);
    }

    public function test_returns_one_when_api_unreachable(): void
    {
        Http::fake([
            'api.test/api/v1/public/aeo' => Http::response(['status' => 'pending'], 202),
            'api.test' => fn () => throw new ConnectionException('cannot reach host'),
        ]);

        $exit = $this->artisan('smking:doctor')->run();

        $this->assertSame(1, $exit);
    }

    public function test_returns_one_when_api_key_missing(): void
    {
        config()->set('smking.api_key', null);
        Http::fake([
            'api.test' => Http::response('ok', 200),
        ]);

        $exit = $this->artisan('smking:doctor')->run();

        $this->assertSame(1, $exit);
    }

    public function test_returns_one_when_api_key_wrong_prefix(): void
    {
        config()->set('smking.api_key', 'sk_should_be_pk');
        Http::fake([
            'api.test' => Http::response('ok', 200),
        ]);

        $exit = $this->artisan('smking:doctor')->run();

        $this->assertSame(1, $exit);
    }
}
