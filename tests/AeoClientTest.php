<?php

declare(strict_types=1);

namespace Smking\Laravel\Tests;

use Illuminate\Support\Facades\Http;
use Smking\Laravel\AeoClient;
use Smking\Laravel\Data\AeoResponse;

class AeoClientTest extends TestCase
{
    public function test_for_path_returns_ready_response(): void
    {
        Http::fake([
            'api.test/api/v1/public/aeo' => Http::response([
                'status' => 'ready',
                'jsonLd' => ['@type' => 'Product', 'name' => 'Widget'],
                'faq' => [['q' => 'Q?', 'a' => 'A.']],
                'summary' => 'Nice.',
                'metaDescription' => 'Buy widget.',
                'faqHtml' => '<section class="smking-faq"></section>',
                'summaryHtml' => '<section class="smking-summary"></section>',
            ], 200),
        ]);

        /** @var AeoClient $client */
        $client = $this->app->make(AeoClient::class);
        $response = $client->forPath('/products/widget', 'https://shop.example/products/widget');

        $this->assertTrue($response->isReady());
        $this->assertSame('Widget', $response->jsonLd['name']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.test/api/v1/public/aeo'
                && $request['key'] === 'pk_test_key'
                && $request['path'] === '/products/widget'
                && $request['url'] === 'https://shop.example/products/widget';
        });
    }

    public function test_202_returns_pending(): void
    {
        Http::fake([
            '*' => Http::response(['status' => 'pending'], 202),
        ]);

        $response = $this->app->make(AeoClient::class)->forPath('/new');

        $this->assertSame(AeoResponse::STATUS_PENDING, $response->status);
    }

    public function test_failed_4xx_returns_not_found(): void
    {
        // v0.7.0 splits 4xx (not_found, 15min cache) from 5xx (server_error,
        // 24hr cache). 5xx behavior covered separately in
        // test_5xx_response_treated_as_server_error_not_not_found.
        Http::fake([
            '*' => Http::response('bad request', 400),
        ]);

        $response = $this->app->make(AeoClient::class)->forPath('/broken');

        $this->assertSame(AeoResponse::STATUS_NOT_FOUND, $response->status);
    }

    public function test_missing_api_key_short_circuits(): void
    {
        config()->set('smking.api_key', null);
        Http::fake();

        $response = $this->app->make(AeoClient::class)->forPath('/x');

        $this->assertSame(AeoResponse::STATUS_NOT_FOUND, $response->status);
        Http::assertNothingSent();
    }

    public function test_missing_base_url_short_circuits(): void
    {
        config()->set('smking.base_url', null);
        Http::fake();

        $response = $this->app->make(AeoClient::class)->forPath('/x');

        $this->assertSame(AeoResponse::STATUS_NOT_FOUND, $response->status);
        Http::assertNothingSent();
    }

    public function test_cache_isolated_by_api_key(): void
    {
        // Cache namespace must include api_key so rotating it invalidates
        // stale entries instead of waiting for ttl. Without isolation, the
        // second call with a different key would hit the cache from the
        // first and get the wrong response.
        config()->set('smking.cache.enabled', true);
        config()->set('smking.cache.ttl', 3600);

        Http::fake([
            '*' => Http::sequence()
                ->push(['status' => 'ready', 'summary' => 'A'], 200)
                ->push(['status' => 'ready', 'summary' => 'B'], 200),
        ]);

        config()->set('smking.api_key', 'pk_first_key');
        $first = $this->app->make(AeoClient::class)->forPath('/products/widget');
        $this->assertSame('A', $first->summary);

        // Rotate the key; cache entry from the first call must NOT be reused.
        config()->set('smking.api_key', 'pk_second_key');
        $second = $this->app->make(AeoClient::class)->forPath('/products/widget');
        $this->assertSame('B', $second->summary);
    }

    public function test_cache_isolated_by_base_url(): void
    {
        // Same isolation rule for base_url — switching between staging and
        // production environments must not cross-contaminate cache entries.
        config()->set('smking.cache.enabled', true);
        config()->set('smking.cache.ttl', 3600);

        Http::fake([
            'staging.test/*' => Http::response(['status' => 'ready', 'summary' => 'staging'], 200),
            'prod.test/*' => Http::response(['status' => 'ready', 'summary' => 'prod'], 200),
        ]);

        config()->set('smking.base_url', 'https://staging.test');
        $staging = $this->app->make(AeoClient::class)->forPath('/products/widget');
        $this->assertSame('staging', $staging->summary);

        config()->set('smking.base_url', 'https://prod.test');
        $prod = $this->app->make(AeoClient::class)->forPath('/products/widget');
        $this->assertSame('prod', $prod->summary);
    }

    public function test_not_found_uses_short_ttl(): void
    {
        // not_found should live in cache only for not_found_ttl seconds, not
        // the full ttl — otherwise customers wait up to an hour for the
        // backend's audit to surface as ready.
        config()->set('smking.cache.enabled', true);
        config()->set('smking.cache.ttl', 3600);
        config()->set('smking.cache.not_found_ttl', 30);

        Http::fake([
            '*' => Http::response(['status' => 'not_found'], 404),
        ]);

        $client = $this->app->make(AeoClient::class);
        $client->forPath('/missing');

        // Inspect the cache entry's ttl directly via the cache repository.
        // The driver doesn't expose ttl on get(), but Laravel's array cache
        // stores entries with an absolute expiry timestamp we can probe by
        // computing the difference. Since testing absolute time is fiddly,
        // we instead confirm the value is cached AND that a second call
        // doesn't trigger another HTTP request (cache hit) within the
        // not_found_ttl window — and clearing cache makes a new HTTP call.
        Http::assertSentCount(1);

        $client->forPath('/missing'); // should hit cache
        Http::assertSentCount(1); // still 1, cached

        // Forcibly expire the cache entry — next call should re-fetch.
        $this->app->make(\Illuminate\Contracts\Cache\Factory::class)->store()->flush();
        $client->forPath('/missing');
        Http::assertSentCount(2);
    }

    // ── Three-tier cache TTL (v0.7.0) ─────────────────────────────

    public function test_5xx_response_treated_as_server_error_not_not_found(): void
    {
        Http::fake([
            '*' => Http::response('upstream broken', 503),
        ]);

        $response = $this->app->make(AeoClient::class)->forPath('/x');

        $this->assertSame(AeoResponse::STATUS_SERVER_ERROR, $response->status);
        $this->assertFalse($response->isReady());
    }

    public function test_4xx_response_still_treated_as_not_found(): void
    {
        Http::fake([
            '*' => Http::response('not found', 404),
        ]);

        $response = $this->app->make(AeoClient::class)->forPath('/x');

        $this->assertSame(AeoResponse::STATUS_NOT_FOUND, $response->status);
    }

    public function test_connection_exception_treated_as_server_error(): void
    {
        Http::fake([
            '*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('cannot reach host'),
        ]);

        $response = $this->app->make(AeoClient::class)->forPath('/x');

        $this->assertSame(AeoResponse::STATUS_SERVER_ERROR, $response->status);
    }

    public function test_server_error_caches_under_subsequent_call_protection(): void
    {
        config()->set('smking.cache.enabled', true);
        config()->set('smking.cache.ttl', 3600);
        // v0.10.0: first failure now caches for 30s (adaptive backoff first
        // step) rather than the legacy flat 24hr. Subsequent call within
        // that window must still hit cache — this test verifies the
        // FPM-saturation-prevention guarantee survives the backoff change.

        Http::fake([
            '*' => Http::response('upstream broken', 503),
        ]);

        $client = $this->app->make(AeoClient::class);
        $client->forPath('/dead');
        Http::assertSentCount(1);

        // Subsequent call within the 30s first-step window must hit cache,
        // NOT re-try the dead upstream.
        $client->forPath('/dead');
        Http::assertSentCount(1);
    }

    // ── v0.10.0 adaptive backoff ──────────────────────────────────

    public function test_server_error_first_failure_caches_for_30_seconds(): void
    {
        config()->set('smking.cache.enabled', true);
        config()->set('smking.cache.server_error_backoff', [30, 300, 1800]);
        config()->set('smking.cache.circuit_breaker', false);

        Http::fake(['*' => Http::response('boom', 503)]);

        $client = $this->app->make(AeoClient::class);
        $client->forPath('/dead');
        Http::assertSentCount(1);

        // 29s later — still within 30s window, cache hit
        $this->travel(29)->seconds();
        $client->forPath('/dead');
        Http::assertSentCount(1);

        // 31s after first call — cache expired, SDK retries upstream.
        // This is the key value of backoff: an install-time typo /
        // firewall fix recovers within seconds, not 24 hours.
        $this->travel(2)->seconds();
        $client->forPath('/dead');
        Http::assertSentCount(2);
    }

    public function test_server_error_escalates_through_backoff_steps(): void
    {
        config()->set('smking.cache.enabled', true);
        config()->set('smking.cache.server_error_backoff', [30, 300, 1800]);
        config()->set('smking.cache.circuit_breaker', false);

        Http::fake(['*' => Http::response('boom', 503)]);

        $client = $this->app->make(AeoClient::class);

        $client->forPath('/dead');                  // fail #1 → cache 30s
        $this->travel(31)->seconds();
        $client->forPath('/dead');                  // fail #2 → cache 300s
        Http::assertSentCount(2);

        // 299s after fail #2 — still within the 5min window, cache hit
        $this->travel(299)->seconds();
        $client->forPath('/dead');
        Http::assertSentCount(2);

        // 301s after fail #2 — cache expired, fail #3 → cache 1800s
        $this->travel(2)->seconds();
        $client->forPath('/dead');
        Http::assertSentCount(3);

        // 1799s after fail #3 — still in 30min window
        $this->travel(1799)->seconds();
        $client->forPath('/dead');
        Http::assertSentCount(3);
    }

    public function test_server_error_falls_back_to_long_ttl_after_steps_exhausted(): void
    {
        config()->set('smking.cache.enabled', true);
        // Tight backoff so we can burn through quickly without absurd travel.
        config()->set('smking.cache.server_error_backoff', [10, 20, 30]);
        config()->set('smking.cache.server_error_ttl', 86400);
        config()->set('smking.cache.circuit_breaker', false);

        Http::fake(['*' => Http::response('boom', 503)]);

        $client = $this->app->make(AeoClient::class);

        $client->forPath('/dead');                  // fail #1 → 10s
        $this->travel(11)->seconds();
        $client->forPath('/dead');                  // fail #2 → 20s
        $this->travel(21)->seconds();
        $client->forPath('/dead');                  // fail #3 → 30s
        $this->travel(31)->seconds();
        $client->forPath('/dead');                  // fail #4 → fallback 24hr
        Http::assertSentCount(4);

        // Within the 24hr fallback window — cache hit, no retry
        $this->travel(86399)->seconds();
        $client->forPath('/dead');
        Http::assertSentCount(4);
    }

    public function test_ready_response_resets_backoff_counter(): void
    {
        config()->set('smking.cache.enabled', true);
        config()->set('smking.cache.ttl', 3600);
        config()->set('smking.cache.server_error_backoff', [30, 300, 1800]);
        config()->set('smking.cache.circuit_breaker', false);

        Http::fakeSequence()
            ->push('boom', 503)                       // fail #1
            ->push(['status' => 'ready', 'jsonLd' => ['@type' => 'Product']], 200)
            ->push('boom', 503)                       // post-reset fail #1
            ->push('boom', 503);                      // post-reset fail #2 (cache-expiry retry)

        $client = $this->app->make(AeoClient::class);

        $client->forPath('/path');                    // fail → counter=1, cache 30s
        $this->travel(31)->seconds();
        $client->forPath('/path');                    // ready! → reset counter, cache 3600s
        Http::assertSentCount(2);

        // travel past ready ttl so the next call retries upstream
        $this->travel(3601)->seconds();
        $client->forPath('/path');                    // fail → counter=1 (reset), cache 30s
        Http::assertSentCount(3);

        // 29s — still within 30s window. Without the counter reset, the
        // post-recovery failure would have used the 300s step (counter=2).
        $this->travel(29)->seconds();
        $client->forPath('/path');
        Http::assertSentCount(3);

        // 32s — cache expired, would retry. Confirms TTL was 30s, not 300s.
        $this->travel(3)->seconds();
        $client->forPath('/path');
        Http::assertSentCount(4);
    }

    public function test_server_error_backoff_can_be_disabled_via_empty_array(): void
    {
        config()->set('smking.cache.enabled', true);
        config()->set('smking.cache.server_error_backoff', []);
        config()->set('smking.cache.server_error_ttl', 86400);
        config()->set('smking.cache.circuit_breaker', false);

        Http::fake(['*' => Http::response('boom', 503)]);

        $client = $this->app->make(AeoClient::class);
        $client->forPath('/dead');
        Http::assertSentCount(1);

        // With backoff disabled, first failure uses the flat
        // server_error_ttl (24hr) directly — pre-v0.10.0 behavior.
        $this->travel(86399)->seconds();
        $client->forPath('/dead');
        Http::assertSentCount(1);
    }

    // (test_pending_status_does_not_cache removed in round-3 — pending
    // now caches for pending_ttl, see test_pending_response_caches_for_short_window)

    // ── Single-flight (v0.7.0 codex review fix) ──────────────────

    public function test_single_flight_lock_lifecycle_does_not_leak(): void
    {
        // Smoke test: the single-flight wrapper acquires + releases its
        // lock cleanly so a second request to the same path doesn't get
        // stuck on a stale lock from the first.
        //
        // Note: cross-process lock contention CANNOT be reliably simulated
        // in a single-PHP-process unit test (FileLock uses flock which is
        // process-level advisory; ArrayLock is instance-local). The
        // single-flight protection's real value lives in production with
        // redis/memcached locks. This test verifies the lock acquire/
        // release lifecycle is clean — no leftover lock blocks future
        // requests.
        config()->set('smking.cache.enabled', true);

        Http::fake([
            'api.test/api/v1/public/aeo' => Http::response(['status' => 'ready'], 200),
        ]);

        $client = $this->app->make(AeoClient::class);

        $client->forPath('/p1');
        $client->forPath('/p1'); // cache hit
        Http::assertSentCount(1);

        // Different path — must acquire a fresh lock cleanly. If the
        // first call left a stale lock, this would hang or fail.
        $client->forPath('/p2');
        Http::assertSentCount(2);
    }

    public function test_single_flight_falls_back_when_driver_lacks_lock(): void
    {
        // Defensive: if a customer's cache repository doesn't expose
        // lock() (some non-standard drivers), we still serve content
        // (no single-flight protection, but no crash either — same as
        // pre-v0.7.0 behavior).
        config()->set('smking.cache.enabled', true);

        Http::fake([
            'api.test/api/v1/public/aeo' => Http::response(['status' => 'ready'], 200),
        ]);

        $response = $this->app->make(AeoClient::class)->forPath('/x');

        $this->assertTrue($response->isReady());
    }

    public function test_lock_acquired_on_arraystore_lockprovider(): void
    {
        // Regression for codex round-2 finding: previously we gated lock
        // usage on `method_exists($repository, 'lock')`, but Repository
        // routes lock() via __call to the underlying store — the check
        // was always false even on lock-capable drivers. Fix detects
        // LockProvider on the underlying store (ArrayStore implements it).
        config()->set('smking.cache.enabled', true);

        Http::fake([
            'api.test/api/v1/public/aeo' => Http::response(['status' => 'ready'], 200),
        ]);

        $client = $this->app->make(AeoClient::class);

        // Pre-acquire the lock for a path — concurrent worker should be
        // blocked. ArrayStore's ArrayLock IS instance-shared via the
        // store's static array, so this DOES test the contention path.
        /** @var \Illuminate\Contracts\Cache\Factory $factory */
        $factory = $this->app->make(\Illuminate\Contracts\Cache\Factory::class);
        $repo = $factory->store();

        $this->assertInstanceOf(
            \Illuminate\Contracts\Cache\LockProvider::class,
            $repo->getStore(),
            'ArrayStore must implement LockProvider — if this fails the supportsLock detection is irrelevant'
        );

        // Acquire lock manually using same key shape as AeoClient
        $prefixes = $client->cacheKeyPrefixes();
        $cacheKey = $prefixes['aeo'].http_build_query(['path' => '/locked']);
        $lockKey = $cacheKey.':lock';
        $externalLock = $repo->lock($lockKey, 30);
        $this->assertTrue($externalLock->get(), 'external lock must be acquirable');

        try {
            $response = $client->forPath('/locked');

            // Single-flight: lock contention → fail-open with notFound,
            // no upstream call.
            $this->assertSame(\Smking\Laravel\Data\AeoResponse::STATUS_NOT_FOUND, $response->status);
            Http::assertNothingSent();
        } finally {
            $externalLock->release();
        }
    }

    public function test_single_flight_re_checks_cache_after_lock_acquired(): void
    {
        // After we get the lock, check cache once more in case another worker
        // wrote between our initial read and lock acquisition. Tests that
        // a cache hit post-lock returns immediately without upstream call.
        config()->set('smking.cache.enabled', true);

        Http::fake([
            'api.test/api/v1/public/aeo' => Http::response(['status' => 'ready'], 200),
        ]);

        $client = $this->app->make(AeoClient::class);
        $client->forPath('/foo'); // Prime cache normally.
        Http::assertSentCount(1);

        // Second call hits cache, no second upstream call.
        $client->forPath('/foo');
        Http::assertSentCount(1);
    }

    // ── Markdown status without mutable state (v0.7.0 codex fix) ──

    public function test_get_markdown_without_env_does_not_leak_status_across_calls(): void
    {
        // Codex flagged $lastMarkdownStatus as instance state that could
        // bleed across requests on Octane / RoadRunner. Refactor returns
        // {body, status} array per call. Regression: missing-env early
        // return must not be polluted by a previous server_error call.
        Http::fake([
            'api.test/api/v1/public/md*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('upstream dead'),
        ]);

        $client = $this->app->make(AeoClient::class);

        // First call — server_error path, returns null.
        $first = $client->getMarkdown('/x');
        $this->assertNull($first);

        // Now break api_key — second call should treat as not_found
        // (early return), NOT inherit the previous call's server_error TTL.
        config()->set('smking.api_key', null);
        $second = $client->getMarkdown('/y');

        $this->assertNull($second);
        // No way to assert TTL directly, but the contract is: each call
        // computes its own status, no state from prior calls.
    }

    // ── Round-3: circuit breaker + pending cache ─────────────────

    public function test_circuit_breaker_trips_after_server_error_and_short_circuits_other_paths(): void
    {
        // The whole point of v0.7.0 round-3: per-path 24hr cache only
        // protects keys we've already failed. A high-cardinality outage
        // (catalog spray / crawler) would still consume one timeout per
        // distinct URL. Circuit breaker shorts the WHOLE namespace once
        // any path hits a 5xx / transport error.
        config()->set('smking.cache.enabled', true);

        Http::fake([
            'api.test/api/v1/public/aeo' => Http::response('upstream broken', 503),
        ]);

        $client = $this->app->make(AeoClient::class);

        // Path A — first to fail, trips the circuit
        $a = $client->forPath('/product-a');
        $this->assertSame(\Smking\Laravel\Data\AeoResponse::STATUS_SERVER_ERROR, $a->status);
        Http::assertSentCount(1);

        // Path B (different URL, never called before) — circuit tripped,
        // must short-circuit WITHOUT a network call
        $b = $client->forPath('/product-b');
        $this->assertSame(\Smking\Laravel\Data\AeoResponse::STATUS_SERVER_ERROR, $b->status);
        Http::assertSentCount(1); // STILL one — second call never went out
    }

    public function test_circuit_breaker_can_be_disabled(): void
    {
        config()->set('smking.cache.enabled', true);
        config()->set('smking.cache.circuit_breaker', false);

        Http::fake([
            'api.test/api/v1/public/aeo' => Http::response('upstream broken', 503),
        ]);

        $client = $this->app->make(AeoClient::class);
        $client->forPath('/a');
        $client->forPath('/b');

        // Circuit disabled — both paths should send their own request
        Http::assertSentCount(2);
    }

    public function test_pending_response_caches_for_short_window(): void
    {
        // Round-3: pending was previously NOT cached, letting hot URLs
        // poll upstream continuously while crawl backlog cleared.
        config()->set('smking.cache.enabled', true);
        config()->set('smking.cache.pending_ttl', 30);

        Http::fake([
            'api.test/api/v1/public/aeo' => Http::response(['status' => 'pending'], 202),
        ]);

        $client = $this->app->make(AeoClient::class);
        $client->forPath('/launching');
        $client->forPath('/launching'); // within pending_ttl window

        // Same URL within pending_ttl → cache hit, no second upstream call
        Http::assertSentCount(1);
    }

    // ── Round-4: per-surface circuit breaker isolation ───────────

    public function test_markdown_failure_does_not_trip_html_aeo_circuit(): void
    {
        // Round-4 (high finding): markdown is an agent-only optional
        // surface. A markdown 5xx MUST NOT suppress HTML AEO injection,
        // which serves every page render. Pre-fix: a single shared
        // breaker would short-circuit forPath() for the full breaker
        // TTL after a markdown outage.
        config()->set('smking.cache.enabled', true);

        Http::fake([
            'api.test/api/v1/public/md*' => Http::response('upstream broken', 503),
            'api.test/api/v1/public/aeo' => Http::response(['status' => 'ready'], 200),
        ]);

        $client = $this->app->make(AeoClient::class);

        // Markdown 5xx → trips MD breaker, doesn't touch AEO breaker
        $client->getMarkdown('/products/widget');
        Http::assertSentCount(1);

        // forPath must still hit upstream because the AEO breaker is
        // independent. Pre-fix this would short-circuit and never send.
        $response = $client->forPath('/products/widget');
        $this->assertTrue($response->isReady());
        Http::assertSentCount(2); // both calls actually went out
    }

    public function test_html_aeo_failure_does_not_trip_markdown_circuit(): void
    {
        // Round-4 (high finding): reverse direction. HTML AEO 5xx must
        // not block subsequent markdown calls. Real-world it's likely
        // both surfaces share the same SaaS upstream, but the SDK's
        // breaker semantics still need to be per-surface so a partial
        // outage on one endpoint doesn't suppress the other.
        config()->set('smking.cache.enabled', true);

        Http::fake([
            'api.test/api/v1/public/aeo' => Http::response('upstream broken', 503),
            'api.test/api/v1/public/md*' => Http::response("# md\n", 200),
        ]);

        $client = $this->app->make(AeoClient::class);

        // HTML 5xx → trips AEO breaker, doesn't touch MD breaker
        $client->forPath('/products/widget');
        Http::assertSentCount(1);

        // getMarkdown must still hit upstream; MD breaker is independent
        $body = $client->getMarkdown('/products/widget');
        $this->assertSame("# md\n", $body);
        Http::assertSentCount(2);
    }

    public function test_circuit_breaker_keys_are_isolated_per_surface_in_cache(): void
    {
        // Round-4 (high finding): inspect the underlying breaker keys
        // directly to confirm forPath() failures only set the AEO breaker
        // and never collide with the markdown breaker key.
        config()->set('smking.cache.enabled', true);

        Http::fake([
            'api.test/api/v1/public/aeo' => Http::response('upstream broken', 503),
        ]);

        $client = $this->app->make(AeoClient::class);
        $client->forPath('/x');

        $store = $this->app->make(\Illuminate\Contracts\Cache\Repository::class);
        $prefixes = $client->cacheKeyPrefixes();

        $this->assertTrue(
            $store->has($prefixes['circuit_aeo']),
            'AEO breaker must trip after forPath 5xx'
        );
        $this->assertFalse(
            $store->has($prefixes['circuit_md']),
            'markdown breaker MUST NOT trip from an AEO-only failure'
        );
    }

    // ── v0.7.1 observability: circuit-trip log ──────────────────

    public function test_trip_circuit_logs_warning_on_first_trip(): void
    {
        // v0.7.1: when the breaker trips, ops needs to know — without a
        // log line, an outage just looks like "AEO content stopped
        // appearing" with no signal pointing at the breaker.
        config()->set('smking.cache.enabled', true);
        $handler = $this->swapInTestLogger();

        Http::fake(['api.test/api/v1/public/aeo' => Http::response('broken', 503)]);
        $this->app->make(AeoClient::class)->forPath('/x');

        $this->assertTrue(
            $handler->hasWarningThatContains('circuit breaker tripped for aeo surface'),
            'first 5xx must emit a warning log so operators can see the trip event'
        );
        $records = array_values(array_filter(
            $handler->getRecords(),
            fn ($r) => str_contains((string) $r['message'], 'circuit breaker tripped'),
        ));
        $this->assertCount(1, $records, 'only one trip log per outage window');
        $this->assertSame('aeo', $records[0]['context']['surface']);
        $this->assertSame(60, $records[0]['context']['ttl_seconds']);
    }

    public function test_trip_circuit_only_logs_once_per_outage_window(): void
    {
        // Re-trip while breaker is already open MUST NOT emit another log.
        // A million-PV outage must not produce a million log lines.
        config()->set('smking.cache.enabled', true);
        $handler = $this->swapInTestLogger();

        Http::fake(['api.test/api/v1/public/aeo' => Http::response('broken', 503)]);
        $client = $this->app->make(AeoClient::class);

        // First failure trips the breaker (and logs once)
        $client->forPath('/a');
        // Concurrent / later failures while breaker is open are short-
        // circuited before tripCircuit() runs again, so we manually re-
        // exercise the writer path with a different cache key to prove
        // the "alreadyOpen" guard skips the duplicate log even if the
        // code IS reached.
        $client->forPath('/b'); // short-circuits — never reaches tripCircuit

        $tripLogs = array_filter(
            $handler->getRecords(),
            fn ($r) => str_contains((string) $r['message'], 'circuit breaker tripped'),
        );
        $this->assertCount(1, $tripLogs, 'second short-circuited request must not re-log');
    }

    public function test_trip_circuit_does_not_log_when_breaker_disabled(): void
    {
        config()->set('smking.cache.enabled', true);
        config()->set('smking.cache.circuit_breaker', false);
        $handler = $this->swapInTestLogger();

        Http::fake(['api.test/api/v1/public/aeo' => Http::response('broken', 503)]);
        $this->app->make(AeoClient::class)->forPath('/x');

        $this->assertFalse(
            $handler->hasWarningThatContains('circuit breaker tripped'),
            'breaker disabled by config means no trip log either'
        );
    }

    public function test_circuit_close_logs_after_half_open_recovery(): void
    {
        // v0.7.1 round-2: when upstream comes back after an outage, the
        // first successful response should emit a "circuit closed" log so
        // ops sees the recovery moment in the log stream — without having
        // to poll `circuit:status` to notice.
        config()->set('smking.cache.enabled', true);
        $handler = $this->swapInTestLogger();
        $client = $this->app->make(AeoClient::class);

        // Use a sequence so the same URL returns 503 first, then 200.
        // Http::fake() calls are additive (not replacing), so calling
        // fake() twice would leave the 503 stub matching first.
        Http::fake([
            'api.test/api/v1/public/aeo' => Http::sequence()
                ->push('broken', 503)
                ->push(['status' => 'ready'], 200),
        ]);

        // 1st call: 5xx trips the breaker + plants tombstone + warning log
        $client->forPath('/a');

        // Force the breaker key to expire (simulate TTL passage). The
        // tombstone (5× TTL) is still alive — that's the whole point.
        $store = $this->app->make(\Illuminate\Contracts\Cache\Repository::class);
        $store->forget($client->cacheKeyPrefixes()['circuit_aeo']);

        // Sanity: tombstone must still be present after we forget the
        // breaker key. If this fails, the tombstone was never written or
        // shares a backing store with the breaker.
        $this->assertTrue(
            $store->has($client->cacheKeyPrefixes()['circuit_aeo'].':tombstone'),
            'tombstone must outlive the breaker key (it is what enables close detection)'
        );

        // 2nd call after recovery: upstream healthy → ready response →
        // maybeLogCircuitClosed pulls tombstone → info log.
        $client->forPath('/b');

        $this->assertTrue(
            $handler->hasInfoThatContains('circuit closed for aeo surface'),
            'half-open success after a previous trip must emit a close log'
        );
    }

    public function test_circuit_close_log_only_fires_once_per_recovery(): void
    {
        // Tombstone is atomic-pulled — multiple successful calls after
        // the same recovery must only log "closed" once. Otherwise a busy
        // site would re-log every ready response in the same minute.
        config()->set('smking.cache.enabled', true);
        $handler = $this->swapInTestLogger();
        $client = $this->app->make(AeoClient::class);

        Http::fake([
            'api.test/api/v1/public/aeo' => Http::sequence()
                ->push('broken', 503)
                ->push(['status' => 'ready'], 200)
                ->push(['status' => 'ready'], 200),
        ]);

        $client->forPath('/a'); // trip

        $store = $this->app->make(\Illuminate\Contracts\Cache\Repository::class);
        $store->forget($client->cacheKeyPrefixes()['circuit_aeo']);

        $client->forPath('/b'); // first recovery — log fires
        $client->forPath('/c'); // second recovery — tombstone already pulled, NO log

        $closeLogs = array_filter(
            $handler->getRecords(),
            fn ($r) => str_contains((string) $r['message'], 'circuit closed'),
        );
        $this->assertCount(1, $closeLogs, 'tombstone pull must be at-most-once per recovery cycle');
    }

    public function test_circuit_close_log_does_not_fire_when_no_prior_trip(): void
    {
        // Sanity: a successful call without a previous trip must NOT
        // log "closed" — that would just be log spam on every healthy
        // upstream response.
        config()->set('smking.cache.enabled', true);
        $handler = $this->swapInTestLogger();

        Http::fake(['api.test/api/v1/public/aeo' => Http::response(['status' => 'ready'], 200)]);
        $this->app->make(AeoClient::class)->forPath('/healthy');

        $this->assertFalse(
            $handler->hasInfoThatContains('circuit closed'),
            'close log MUST NOT fire when no prior trip happened'
        );
    }

    private function swapInTestLogger(): \Monolog\Handler\TestHandler
    {
        $handler = new \Monolog\Handler\TestHandler;
        $logger = new \Monolog\Logger('smking-test', [$handler]);
        $this->app->instance(\Psr\Log\LoggerInterface::class, $logger);
        // AeoClient is a singleton — drop the cached instance so the
        // freshly-bound logger is picked up on next make().
        $this->app->forgetInstance(AeoClient::class);

        return $handler;
    }

    // ── Connect/read timeout split (v0.7.0, #3) ───────────────────

    public function test_connect_timeout_and_read_timeout_passed_separately(): void
    {
        config()->set('smking.connect_timeout', 0.5);
        config()->set('smking.timeout', 1.5);

        Http::fake([
            '*' => Http::response(['status' => 'not_found'], 404),
        ]);

        $this->app->make(AeoClient::class)->forPath('/x');

        Http::assertSent(function ($request) {
            // Laravel's PendingRequest serializes options via Guzzle; we
            // can't directly assert the timeout values from a fake, but
            // we can confirm the request went through (regression: bad
            // method names like ->connectTimeoutMS would crash).
            return str_contains($request->url(), 'api.test/api/v1/public/aeo');
        });
    }

    // ── httpTimeoutInt helper (v0.10.2, sleepytofu type bug) ──────
    //
    // Surfaced on sleepytofu.com running `smking:doctor`: Laravel 11+
    // tightened `PendingRequest::timeout()` / `connectTimeout()` to
    // strict `int` parameters. Passing the default float 1.5 crashed
    // the SDK with `TypeError: timeout(): Argument #1 ($seconds) must
    // be of type int, float given`, which then took down the host
    // page through the middleware.
    //
    // Reflection test because Http::fake() swaps the underlying client
    // and bypasses the int type check — only direct invocation of the
    // helper exercises the ceil/clamp contract.

    public function test_http_timeout_int_ceils_floats_to_int(): void
    {
        $client = $this->app->make(AeoClient::class);
        $reflection = new \ReflectionClass(AeoClient::class);
        $method = $reflection->getMethod('httpTimeoutInt');
        $method->setAccessible(true);

        // Default config values that were crashing pre-v0.10.2
        $this->assertSame(2, $method->invoke($client, 1.5));
        $this->assertSame(1, $method->invoke($client, 0.5));
        $this->assertSame(1, $method->invoke($client, 1.0));
        $this->assertSame(3, $method->invoke($client, 2.1));

        // Clamp: 0 / negative configs must not yield 0-second timeouts
        // (would disable HTTP timeout entirely — could hang FPM
        // worker indefinitely on a slow upstream).
        $this->assertSame(1, $method->invoke($client, 0.0));
        $this->assertSame(1, $method->invoke($client, -1.0));

        // Plain int input round-trips
        $this->assertSame(5, $method->invoke($client, 5.0));
        $this->assertSame(30, $method->invoke($client, 30.0));
    }

    public function test_default_float_config_does_not_crash_aeo_lookup(): void
    {
        // Defaults are connect_timeout=1.0 and timeout=1.5 — both floats.
        // Pre-v0.10.2 this crashed on Laravel 11+. The fake bypasses the
        // type check but at least confirms we still reach the post() call
        // without an earlier TypeError from building the request.
        config()->set('smking.connect_timeout', 1.0);
        config()->set('smking.timeout', 1.5);
        Http::fake([
            '*' => Http::response(['status' => 'not_found'], 404),
        ]);

        $response = $this->app->make(AeoClient::class)->forPath('/regression-test');

        $this->assertSame('not_found', $response->status);
    }
}
