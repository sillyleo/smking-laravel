<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests\Console;

use Illuminate\Support\Facades\Http;
use Smking\Laravel\AeoClient;
use Smking\Laravel\Tests\TestCase;

class CircuitStatusCommandTest extends TestCase
{
    public function test_status_shows_closed_state_when_no_breakers_tripped(): void
    {
        config()->set('smking.cache.enabled', true);

        $this->artisan('smking:circuit:status')
            ->expectsOutputToContain('HTML AEO injection')
            ->expectsOutputToContain('markdown for agents')
            ->assertExitCode(0);
    }

    public function test_status_shows_open_state_after_aeo_breaker_trips(): void
    {
        // Status must surface the breaker state without forcing a
        // cache:purge (which has the side effect of resetting it).
        config()->set('smking.cache.enabled', true);
        Http::fake(['api.test/api/v1/public/aeo' => Http::response('broken', 503)]);

        $this->app->make(AeoClient::class)->forPath('/x');

        // AEO surface tripped → command output must show OPEN for aeo,
        // closed for md (per-surface isolation, v0.7.0 round-4).
        $this->artisan('smking:circuit:status')
            ->expectsOutputToContain('OPEN')
            ->expectsOutputToContain('closed')
            ->assertExitCode(1);
    }

    public function test_status_returns_failure_when_any_surface_open(): void
    {
        config()->set('smking.cache.enabled', true);
        Http::fake([
            'api.test/api/v1/public/md*' => Http::response('broken', 503),
        ]);

        $this->app->make(AeoClient::class)->getMarkdown('/x');

        // md tripped, aeo closed — command must still exit non-zero
        // (script-friendly: any open breaker is a failure signal).
        $this->artisan('smking:circuit:status')
            ->assertExitCode(1);
    }

    public function test_status_shows_disabled_when_breaker_off(): void
    {
        config()->set('smking.cache.enabled', true);
        config()->set('smking.cache.circuit_breaker', false);

        $this->artisan('smking:circuit:status')
            ->expectsOutputToContain('DISABLED')
            ->assertExitCode(0);
    }

    public function test_status_includes_cache_key_for_ops_debugging(): void
    {
        // Ops needs the actual cache key to inspect the underlying store
        // (`redis-cli get smking:circuit:aeo:...`) when triaging an
        // unexpected breaker state.
        config()->set('smking.cache.enabled', true);

        $this->artisan('smking:circuit:status')
            ->expectsOutputToContain('smking:circuit:aeo:')
            ->expectsOutputToContain('smking:circuit:md:')
            ->assertExitCode(0);
    }
}
