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
        // v0.8.0: doctor no longer probes takeover endpoints (middleware
        // takeover removed; sitemap / llms.txt now served by dedicated
        // controllers registered via wizard).
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
            'api.test/api/v1/public/sitemap.xml*' => Http::response('<urlset/>', 200),
            'api.test/api/v1/public/robots.txt*' => Http::response('', 200),
            'api.test/api/v1/public/llms-txt*' => Http::response('', 200),
        ]);

        $exit = $this->artisan('smking:doctor')->run();

        $this->assertSame(0, $exit);
    }

    // ── Config schema drift check (v0.6.3) ────────────────────────

    public function test_doctor_reports_missing_keys_when_published_config_lags(): void
    {
        $configPath = config_path('smking.php');
        file_put_contents($configPath, "<?php return ['api_key' => 'pk_x', 'base_url' => 'https://api.test'];");

        Http::fake([
            'api.test/api/v1/public/aeo' => Http::response([], 400),
        ]);

        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        \Illuminate\Support\Facades\Artisan::call('smking:doctor', [], $output);
        $display = $output->fetch();

        $this->assertStringContainsString('Config schema drift', $display);
        $this->assertStringContainsString('new key(s)', $display);
    }

    public function test_doctor_reports_in_sync_when_published_config_matches_package(): void
    {
        // Copy the package's config verbatim into the user config — no drift.
        $packageConfig = file_get_contents(__DIR__.'/../../config/smking.php');
        file_put_contents(config_path('smking.php'), $packageConfig);

        Http::fake([
            'api.test/api/v1/public/aeo' => Http::response([], 400),
        ]);

        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        \Illuminate\Support\Facades\Artisan::call('smking:doctor', [], $output);

        $this->assertStringContainsString('in sync with package defaults', $output->fetch());
    }

    public function test_doctor_drift_check_is_info_only_never_fails(): void
    {
        // Even with major drift, the doctor must still exit 0 if other
        // hard checks pass. Drift is informational, not a failure.
        $configPath = config_path('smking.php');
        file_put_contents($configPath, "<?php return [];"); // empty — every package key is "missing"

        Http::fake([
            'api.test/api/v1/public/aeo' => Http::response([], 400),
            'api.test/api/v1/public/sitemap.xml*' => Http::response('<urlset/>', 200),
            'api.test/api/v1/public/robots.txt*' => Http::response('', 200),
            'api.test/api/v1/public/llms-txt*' => Http::response('', 200),
        ]);

        $exit = $this->artisan('smking:doctor')->run();

        $this->assertSame(0, $exit);
    }

    public function test_doctor_drift_check_skips_when_config_not_published(): void
    {
        $configPath = config_path('smking.php');
        if (file_exists($configPath)) {
            @unlink($configPath);
        }

        Http::fake([
            'api.test/api/v1/public/aeo' => Http::response([], 400),
        ]);

        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        \Illuminate\Support\Facades\Artisan::call('smking:doctor', [], $output);

        $this->assertStringContainsString('drift check skipped', $output->fetch());
    }

    // v0.8.0: removed 3 takeover tests (test_doctor_reports_takeover_flag_state_per_file,
    // test_doctor_passes_when_takeover_endpoints_return_2xx,
    // test_doctor_fails_when_takeover_endpoint_returns_401) — middleware
    // takeover removed in favour of dedicated controllers registered via
    // smking-wizard. Doctor no longer probes those endpoints.

    /**
     * v0.10.1: `--json` flag emits structured output for @smking/wizard.
     * Stable contract: { checks: [...], summary: { passed, failed, info, ok } }.
     * Shape break here = breaks the wizard's run_doctor parser.
     */
    public function test_json_flag_emits_valid_parseable_output_with_summary_ok_true_when_all_pass(): void
    {
        Http::fake([
            'api.test/api/v1/public/aeo' => Http::response(['error' => 'missing key'], 400),
            'api.test/api/v1/public/sitemap.xml*' => Http::response('<urlset/>', 200),
            'api.test/api/v1/public/robots.txt*' => Http::response("User-agent: *\nAllow: /\n", 200),
            'api.test/api/v1/public/llms-txt*' => Http::response("# index\n", 200),
        ]);

        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        $exit = \Illuminate\Support\Facades\Artisan::call(
            'smking:doctor',
            ['--path' => '/', '--json' => true],
            $output,
        );
        $stdout = trim($output->fetch());

        $this->assertSame(0, $exit);

        $payload = json_decode($stdout, true);
        $this->assertIsArray($payload, 'doctor --json must emit valid JSON');
        $this->assertArrayHasKey('checks', $payload);
        $this->assertArrayHasKey('summary', $payload);
        $this->assertTrue($payload['summary']['ok'], 'summary.ok should be true when no checks failed');
        $this->assertSame(0, $payload['summary']['failed']);
        $this->assertGreaterThan(0, $payload['summary']['passed']);

        // Each check must have the {status, label, detail} shape the wizard
        // depends on. Catching schema drift here protects the wizard contract.
        foreach ($payload['checks'] as $check) {
            $this->assertArrayHasKey('status', $check);
            $this->assertArrayHasKey('label', $check);
            $this->assertArrayHasKey('detail', $check);
            $this->assertContains($check['status'], ['pass', 'fail', 'info']);
        }
    }

    public function test_json_flag_returns_summary_ok_false_when_a_check_fails(): void
    {
        // Sabotage api_key so checkApiKey fails — easiest deterministic fail.
        config()->set('smking.api_key', null);
        Http::fake([
            'api.test/api/v1/public/aeo' => Http::response([], 400),
            'api.test/api/v1/public/sitemap.xml*' => Http::response('', 200),
            'api.test/api/v1/public/robots.txt*' => Http::response('', 200),
            'api.test/api/v1/public/llms-txt*' => Http::response('', 200),
        ]);

        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        $exit = \Illuminate\Support\Facades\Artisan::call(
            'smking:doctor',
            ['--json' => true],
            $output,
        );
        $payload = json_decode(trim($output->fetch()), true);

        $this->assertSame(1, $exit);
        $this->assertIsArray($payload);
        $this->assertFalse($payload['summary']['ok']);
        $this->assertGreaterThan(0, $payload['summary']['failed']);
    }
}
