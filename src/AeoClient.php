<?php

declare(strict_types=1);

namespace Smking\Laravel;

use Composer\InstalledVersions;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Log\LoggerInterface;
use Smking\Laravel\Data\AeoResponse;
use Throwable;

/**
 * Thin client over the smking Public AEO API.
 *
 * Wraps Laravel's HTTP client with caching + graceful failure: a slow upstream
 * or network error must never break a page render, so every error path returns
 * a not_found response and logs for diagnostics.
 */
class AeoClient
{
    private const TELEMETRY_OUTBOX_MAX_EVENTS = 20;

    private const TELEMETRY_OUTBOX_TTL_SECONDS = 86400;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly CacheFactory $cache,
        private readonly ConfigRepository $config,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Discover AEO content for a path. Uses POST so the server registers the
     * path for background crawling when it hasn't been seen before.
     */
    public function forPath(string $path, ?string $url = null): AeoResponse
    {
        return $this->remember(['path' => $path], function (callable $onTransportFailure) use ($path, $url) {
            return $this->discover(['path' => $path, 'url' => $url], $onTransportFailure);
        });
    }

    public function forProductId(int $productId): AeoResponse
    {
        return $this->remember(['product_id' => $productId], function (callable $onTransportFailure) use ($productId) {
            return $this->discover(['product_id' => $productId], $onTransportFailure);
        });
    }

    /**
     * Fetch markdown rendition of a path's AEO content for agent clients
     * (autonomous buyers, browser agents, MCP clients) that send
     * `Accept: text/markdown`. Returns the markdown body or null when the
     * backend has no ready content for this path / API is unreachable.
     *
     * Result is cached separately from forPath() under a `smking:md:` prefix
     * so the two never collide on cache keys (one stores AeoResponse, the
     * other a string). Cache namespace still rotates with api_key + base_url
     * the same way forPath() does.
     */
    public function getMarkdown(string $path): ?string
    {
        return $this->rememberMarkdown($path, function () use ($path): array {
            return $this->fetchMarkdown($path);
        });
    }

    /**
     * @return array{body: ?string, status: string}
     *   body   — markdown content, or null if missing/unreachable
     *   status — ready | not_found | server_error (drives cache TTL choice)
     */
    private function fetchMarkdown(string $path): array
    {
        $apiKey = $this->apiKey();
        if ($apiKey === null) {
            return ['body' => null, 'status' => AeoResponse::STATUS_NOT_FOUND];
        }

        if ($this->baseUrl() === null) {
            $this->logger?->warning('smking: SMKING_BASE_URL is not configured; set it in your .env to enable markdown rendering.');

            return ['body' => null, 'status' => AeoResponse::STATUS_NOT_FOUND];
        }

        try {
            $response = $this->http
                ->connectTimeout($this->httpTimeoutInt($this->connectTimeout()))
                ->timeout($this->httpTimeoutInt($this->readTimeout()))
                ->withHeaders(['Accept' => 'text/markdown'])
                ->get($this->endpoint('/api/v1/public/md'), [
                    'key' => $apiKey,
                    'path' => $path,
                ]);
        } catch (Throwable $e) {
            $this->logger?->warning('smking: markdown fetch failed', [
                'message' => $e->getMessage(),
                'path' => $path,
            ]);

            // v0.7.0: signal "server_error" so the cache layer applies the
            // long 24hr TTL instead of the short not_found 15min.
            return ['body' => null, 'status' => AeoResponse::STATUS_SERVER_ERROR];
        }

        if (! $response->successful()) {
            return [
                'body' => null,
                'status' => $response->status() >= 500
                    ? AeoResponse::STATUS_SERVER_ERROR
                    : AeoResponse::STATUS_NOT_FOUND,
            ];
        }

        $body = $response->body();

        return [
            'body' => $body !== '' ? $body : null,
            'status' => $body !== '' ? AeoResponse::STATUS_READY : AeoResponse::STATUS_NOT_FOUND,
        ];
    }

    /**
     * @param  callable(): array{body: ?string, status: string}  $resolver
     */
    private function rememberMarkdown(string $path, callable $resolver): ?string
    {
        $cacheConfig = $this->config->get('smking.cache', []);
        $enabled = (bool) ($cacheConfig['enabled'] ?? true);
        $ttl = (int) ($cacheConfig['ttl'] ?? 3600);

        if (! $enabled || $ttl <= 0) {
            return $resolver()['body'];
        }

        $store = $cacheConfig['store'] ?? null;
        $repository = $store ? $this->cache->store($store) : $this->cache->store();

        // Independent prefix from forPath() — different value type (string
        // vs AeoResponse) means they must never share cache keys.
        $cacheKey = ($cacheConfig['markdown_prefix'] ?? 'smking:md:').$this->cacheNamespace().':'.http_build_query(['path' => $path]);

        $cached = $repository->get($cacheKey);
        if (is_string($cached)) {
            return $cached;
        }
        // Cached miss is encoded as the literal `false` so we don't re-hit
        // the API on every request to a path the backend hasn't crawled
        // yet. TTL matches not_found_ttl from forPath() — short, so the
        // first-ready response surfaces quickly.
        if ($cached === false) {
            return null;
        }

        // Per-surface circuit breaker — markdown surface only. v0.7.0
        // round-4: independent of the AEO breaker so a markdown outage
        // never suppresses HTML AEO injection.
        if ($this->circuitOpen($repository, 'md')) {
            return null;
        }

        // v0.7.0 single-flight protection — same logic as forPath()'s
        // singleFlight(), adapted for the markdown surface (cached value
        // is string|false rather than AeoResponse).
        return $this->singleFlightMarkdown($repository, $cacheKey, $cacheConfig, $ttl, $resolver);
    }

    /**
     * Single-flight wrapper for markdown fetches. Same protection model
     * as {@see singleFlight()} but adapted for the markdown cache value
     * type (string body | false sentinel).
     *
     * @param  array<string, mixed>  $cacheConfig
     * @param  callable(): array{body: ?string, status: string}  $resolver
     */
    private function singleFlightMarkdown(
        \Illuminate\Contracts\Cache\Repository $repository,
        string $cacheKey,
        array $cacheConfig,
        int $ttl,
        callable $resolver,
    ): ?string {
        $writeResult = function (array $result) use ($repository, $cacheKey, $cacheConfig, $ttl): void {
            if ($result['body'] === null) {
                if ($result['status'] === AeoResponse::STATUS_SERVER_ERROR) {
                    // v0.10.0: adaptive backoff — first failure caches for
                    // 30s (auto-retry catches install typos / firewall
                    // configuration in seconds, not 24 hours). Each
                    // subsequent failure escalates until we reach
                    // server_error_ttl (default 24hr) for steady-state
                    // outage protection.
                    $failCount = $this->bumpFailureCount($repository, $cacheKey);
                    $writeTtl = $this->backoffTtlForFailures($cacheConfig, $failCount);
                } else {
                    $writeTtl = min($ttl, (int) ($cacheConfig['not_found_ttl'] ?? 900));
                }
                $repository->put($cacheKey, false, $writeTtl);

                // v0.7.0 round-4: markdown 5xx / transport failure trips
                // ONLY the markdown surface breaker. HTML AEO injection
                // (forPath) keeps serving cached / live content because
                // it talks to a different upstream endpoint.
                if ($result['status'] === AeoResponse::STATUS_SERVER_ERROR) {
                    $this->tripCircuit($repository, 'md');
                }

                return;
            }
            $repository->put($cacheKey, $result['body'], $ttl);

            // v0.10.0: success clears the failure counter so the next
            // outage starts at the 30s backoff step, not whatever step
            // a long-ago failure left behind.
            $this->resetFailureCount($repository, $cacheKey);

            // v0.7.1: markdown half-open success — log close event when
            // recovering from a previous markdown outage.
            $this->maybeLogCircuitClosed($repository, 'md');
        };

        if (! $this->supportsLock($repository)) {
            $result = $resolver();
            $writeResult($result);

            return $result['body'];
        }

        $lockKey = $cacheKey.':lock';
        $lockTtl = max(5, (int) ceil($this->readTimeout() + $this->connectTimeout() + 2));

        $lock = $repository->lock($lockKey, $lockTtl);
        if (! $lock->get()) {
            // Another worker is fetching upstream — fail open with null
            // (middleware falls through to HTML).
            return null;
        }

        try {
            // Re-check after lock acquired — race between get() and lock().
            $cached = $repository->get($cacheKey);
            if (is_string($cached)) {
                return $cached;
            }
            if ($cached === false) {
                return null;
            }

            $result = $resolver();
            $writeResult($result);

            return $result['body'];
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array{path?: string, url?: ?string, product_id?: int}  $body
     * @param  null|callable(): bool  $onTransportFailure  Returns true only
     *         when this request may spend the cold-start retry budget.
     */
    private function discover(array $body, ?callable $onTransportFailure = null): AeoResponse
    {
        $apiKey = $this->apiKey();
        if ($apiKey === null) {
            return AeoResponse::notFound();
        }

        if ($this->baseUrl() === null) {
            $this->logger?->warning('smking: SMKING_BASE_URL is not configured; set it in your .env to enable AEO discovery.');

            return AeoResponse::notFound();
        }

        $startedAt = microtime(true);
        $requestId = $this->opaqueId();
        $attemptOutcomes = [];
        $httpStatuses = [];
        $circuitAction = 'none';
        $pendingTelemetry = $this->pendingTelemetryEvents();
        $payload = array_filter(
            array_merge(
                [
                    'key' => $apiKey,
                    // Heartbeat piggyback — saas upserts sdk_health_snapshots
                    // so dashboard shows 'SDK active' without an outbound probe
                    // (which Cloudflare WAFs commonly block on Vercel ASN).
                    // Older saas silently ignores this optional field.
                    'sdk_meta' => $this->selfReport($pendingTelemetry),
                ],
                $body,
            ),
            static fn ($value) => $value !== null && $value !== '',
        );

        // First attempt at the steady-state read timeout (default 1.5s). On a
        // warm Vercel function this returns immediately; the tight timeout
        // protects the FPM pool on high-traffic sites.
        $attempt = $this->postDiscover(
            $payload,
            $this->connectTimeout(),
            $this->readTimeout(),
            $requestId,
            1,
        );
        $response = $attempt['response'];
        $attemptOutcomes[] = $attempt['outcome'];
        if ($attempt['http_status'] !== null) {
            $httpStatuses[] = $attempt['http_status'];
        }

        // A transport failure means the upstream path is already unhealthy,
        // even when the optional cold-start retry later receives a valid 4xx.
        // Open the namespace circuit before retrying so concurrent requests
        // for other paths do not each consume their own retry budget.
        $mayRetry = true;
        if ($response === null && $onTransportFailure !== null) {
            $mayRetry = (bool) $onTransportFailure();
            if ($this->circuitProtectionEnabled()) {
                $circuitAction = $mayRetry ? 'opened_retry_owner' : 'retry_denied';
            }
        }

        // Cold-start retry (v0.19.0): low-traffic / newly-launched / off-peak
        // sites don't keep the upstream serverless function warm, so AEO is
        // effectively a cold path (3-5s first-hit) despite the hot-path
        // default the 1.5s read timeout assumes. The first attempt's timeout
        // itself wakes the function; a single retry at a generous timeout then
        // lands on the now-warm instance. Warm sites never reach here (first
        // attempt succeeds) so steady-state latency is unaffected. A genuinely
        // dead upstream fails both attempts → serverError() → the existing
        // adaptive backoff + circuit breaker take over.
        if ($response === null && $mayRetry && $this->coldStartRetryEnabled()) {
            $attempt = $this->postDiscover(
                $payload,
                $this->coldStartConnectTimeout(),
                $this->coldStartTimeout(),
                $requestId,
                2,
            );
            $response = $attempt['response'];
            $attemptOutcomes[] = $attempt['outcome'];
            if ($attempt['http_status'] !== null) {
                $httpStatuses[] = $attempt['http_status'];
            }
        }

        // Receiving any HTTP response proves SaaS received the piggybacked
        // metadata. Transport failures keep the outbox untouched because the
        // request may never have left the customer network.
        if (
            $response !== null
            && $response->header('X-Smking-Request-Id') === $requestId
            && $pendingTelemetry !== []
        ) {
            $this->acknowledgeTelemetryEvents($pendingTelemetry);
        }

        if ($response === null) {
            // DNS / TCP / read timeout / connection error on every attempt →
            // SaaS effectively unreachable. v0.7.0: treat as server_error so
            // cache TTL escalates (vs not_found 15min), saving a million-PV
            // site from hammering a dead upstream.
            $this->logger?->warning('smking: AEO discovery failed', [
                'request_id' => $requestId,
                'cold_start_retry' => $this->coldStartRetryEnabled(),
            ]);
            $result = AeoResponse::serverError();
        } elseif ($response->status() === 202) {
            $result = AeoResponse::pending();
        } elseif (! $response->successful()) {
            // 5xx → SaaS broken on its end → server_error (24hr cache).
            // 4xx → SaaS rejected the request (bad key / unknown path) →
            // not_found (15min cache, lets backend audit catch up).
            if ($response->status() >= 500) {
                $this->logger?->warning('smking: AEO discovery upstream 5xx', [
                    'request_id' => $requestId,
                    'status' => $response->status(),
                ]);
                $result = AeoResponse::serverError();
            } else {
                $result = AeoResponse::notFound();
            }
        } else {
            $json = $response->json();
            $result = is_array($json)
                ? AeoResponse::fromArray($json)
                : AeoResponse::notFound();
        }

        $event = $this->discoveryEvent(
            requestId: $requestId,
            attemptOutcomes: $attemptOutcomes,
            httpStatuses: $httpStatuses,
            circuitAction: $circuitAction,
            finalStatus: $result->status,
            durationMs: (int) round((microtime(true) - $startedAt) * 1000),
        );
        $this->logger?->info('smking: AEO discovery', $event);

        // Successful/4xx requests are already visible in the SaaS Runtime
        // Log. Only outcomes that could not reliably reach SaaS need the
        // bounded local outbox for delivery on a later request.
        if ($result->status === AeoResponse::STATUS_SERVER_ERROR && $response === null) {
            $this->appendTelemetryEvent($event);
        }

        return $result;
    }

    /**
     * Single upstream POST attempt. Returns the response plus a bounded,
     * privacy-safe outcome category. A null response means DNS / TCP / read
     * timeout / connection error and lets {@see discover()} decide whether to
     * cold-start retry before collapsing to serverError().
     *
     * @param  array<string, mixed>  $payload
     * @return array{response: ?\Illuminate\Http\Client\Response, outcome: string, http_status: ?int}
     */
    private function postDiscover(
        array $payload,
        float $connectTimeout,
        float $readTimeout,
        string $requestId,
        int $attempt,
    ): array
    {
        try {
            $response = $this->http
                ->connectTimeout($this->httpTimeoutInt($connectTimeout))
                ->timeout($this->httpTimeoutInt($readTimeout))
                ->withHeaders([
                    'X-Smking-Request-Id' => $requestId,
                    'X-Smking-Attempt' => (string) $attempt,
                ])
                ->acceptJson()
                ->asJson()
                ->post($this->endpoint('/api/v1/public/aeo'), $payload);

            return [
                'response' => $response,
                'outcome' => 'http_'.((int) floor($response->status() / 100)).'xx',
                'http_status' => $response->status(),
            ];
        } catch (Throwable $e) {
            // Debug (not warning): a first-attempt timeout that the cold-start
            // retry then recovers from is normal on cold-path sites — logging
            // it as a warning would cry wolf. discover() emits the single
            // warning only when *all* attempts fail.
            $this->logger?->debug('smking: AEO discovery attempt failed', [
                'request_id' => $requestId,
                'attempt' => $attempt,
                'outcome' => $this->transportOutcome($e),
                'read_timeout' => $readTimeout,
            ]);

            return [
                'response' => null,
                'outcome' => $this->transportOutcome($e),
                'http_status' => null,
            ];
        }
    }

    /**
     * True iff the cache repository's underlying store implements
     * `LockProvider`. Repository itself doesn't declare lock() — it
     * routes via `__call` to the store, so checking method_exists on the
     * Repository wrapper is wrong (always false). Underlying stores that
     * implement LockProvider: redis, memcached, database, array, file,
     * dynamodb. The Null / "external" stores don't.
     */
    private function supportsLock(\Illuminate\Contracts\Cache\Repository $repository): bool
    {
        if (! method_exists($repository, 'getStore')) {
            return false;
        }

        return $repository->getStore() instanceof \Illuminate\Contracts\Cache\LockProvider;
    }

    /**
     * Resolve `cache.connect_timeout` from config; default 1.0s. Floats
     * accepted because Laravel's HTTP client used to support sub-second
     * precision. Internal callers (lock TTL math) still want the float
     * for precision; HTTP-client callers must cast via `httpTimeoutInt()`
     * because Laravel 11+ tightened `connectTimeout(int $seconds)` and
     * `timeout(int $seconds)` to strict int types.
     */
    private function connectTimeout(): float
    {
        $value = $this->config->get('smking.connect_timeout', 1.0);

        return is_numeric($value) ? (float) $value : 1.0;
    }

    /**
     * Resolve `smking.timeout` (read timeout) from config; default 1.5s
     * since v0.7.0 (was 3s). Floats accepted — see connectTimeout() docs
     * for the HTTP-client-int requirement on Laravel 11+.
     */
    private function readTimeout(): float
    {
        $value = $this->config->get('smking.timeout', 1.5);

        return is_numeric($value) ? (float) $value : 1.5;
    }

    /**
     * Cold-start retry toggle (v0.19.0). Default true. Set
     * SMKING_COLD_START_RETRY=false to disable the second attempt — a first-
     * attempt timeout then collapses straight to server_error (pre-v0.19
     * behavior).
     */
    private function coldStartRetryEnabled(): bool
    {
        return (bool) $this->config->get('smking.cold_start_retry', true);
    }

    /**
     * Read timeout (seconds) for the cold-start retry attempt; default 8.0s —
     * generous enough to absorb a Vercel cold start (3-5s) that the first
     * attempt's tight read timeout couldn't. Floats accepted; see
     * connectTimeout() for the httpTimeoutInt() requirement on Laravel 11+.
     */
    private function coldStartTimeout(): float
    {
        $value = $this->config->get('smking.cold_start_timeout', 8.0);

        return is_numeric($value) ? (float) $value : 8.0;
    }

    /**
     * Connect timeout (seconds) for the cold-start retry attempt; default
     * 3.0s — looser than the steady-state connect_timeout to tolerate a
     * sluggish first TCP handshake to a cold edge.
     */
    private function coldStartConnectTimeout(): float
    {
        $value = $this->config->get('smking.cold_start_connect_timeout', 3.0);

        return is_numeric($value) ? (float) $value : 3.0;
    }

    /**
     * v0.10.2: Laravel 11+ tightened HTTP client signatures to
     * `timeout(int $seconds)` and `connectTimeout(int $seconds)`.
     * Passing the raw float from config (1.5) crashes the SDK with a
     * TypeError, then takes down the host page through the middleware.
     * Surfaced by sleepytofu.com running `smking:doctor` post-install.
     *
     * We ceil rather than round/floor: a config-set 1.5s timeout becomes
     * 2s effective, which never makes the timeout *shorter* than what
     * the operator asked for. `max(1, …)` floors negative / zero config
     * values to 1s so a typo can't disable timeouts entirely.
     */
    private function httpTimeoutInt(float $seconds): int
    {
        return max(1, (int) ceil($seconds));
    }

    /**
     * @param  array<string, scalar>  $keyParts
     * @param  callable(callable(): bool): AeoResponse  $resolver
     */
    private function remember(array $keyParts, callable $resolver): AeoResponse
    {
        $cacheConfig = $this->config->get('smking.cache', []);
        $enabled = (bool) ($cacheConfig['enabled'] ?? true);
        $ttl = (int) ($cacheConfig['ttl'] ?? 3600);

        if (! $enabled || $ttl <= 0) {
            return $resolver(static fn (): bool => true);
        }

        $store = $cacheConfig['store'] ?? null;
        $repository = $store ? $this->cache->store($store) : $this->cache->store();

        // Namespace the cache key by (api_key, base_url) so rotating either
        // automatically invalidates stale entries instead of waiting for ttl
        // to expire. Helper extracted in v0.7.0 so the cache-purge command
        // can reconstruct the same prefix.
        $cacheKey = ($cacheConfig['prefix'] ?? 'smking:aeo:').$this->cacheNamespace().':'.http_build_query($keyParts);

        $cached = $repository->get($cacheKey);
        if ($cached instanceof AeoResponse) {
            return $cached;
        }

        // v0.7.0 round-3 → round-4: per-surface circuit breaker. Per-path
        // 24hr server_error cache only protects keys we've already failed;
        // a cold-key burst across N distinct URLs (catalog spray, crawler,
        // sitemap fetch) would still each consume a full timeout before
        // their own server_error entry lands. The breaker shorts the
        // entire AEO surface (HTML injection) for `circuit_breaker_ttl`
        // seconds after any failure, so the second URL in the burst skips
        // upstream entirely. Markdown surface has its own independent
        // breaker so an agent-only outage never trips this one.
        if ($this->circuitOpen($repository, 'aeo')) {
            $this->recordShortCircuit();

            return AeoResponse::serverError();
        }

        // v0.7.0 single-flight protection: under concurrent traffic to a
        // cold key, all workers would otherwise call upstream simultaneously
        // (cache stampede / thundering herd). One worker takes a short lock
        // and does the upstream fetch + cache write; others fail open so
        // worker pool stays healthy.
        $transportAwareResolver = fn (): AeoResponse => $resolver(
            function () use ($repository): bool {
                return $this->tripCircuit($repository, 'aeo');
            },
        );

        return $this->singleFlight($repository, $cacheKey, $transportAwareResolver, function (AeoResponse $response) use ($repository, $cacheKey, $cacheConfig, $ttl): void {
            // Four-tier TTL with adaptive server_error backoff (v0.10.0):
            //   ready        → full ttl (default 1hr)
            //   not_found    → not_found_ttl (default 15min)
            //   server_error → 30s → 5min → 30min → server_error_ttl (default 24hr)
            //                  Escalates per consecutive failure so install
            //                  typos / firewall issues auto-recover in
            //                  seconds, while a real outage still gets the
            //                  full 24hr protection by failure #4.
            //   pending      → pending_ttl (default 15s) — was "never cache"
            //                  but a hot URL launched mid-crawl would
            //                  otherwise poll upstream continuously until
            //                  ready
            $writeTtl = match ($response->status) {
                AeoResponse::STATUS_READY => $ttl,
                AeoResponse::STATUS_NOT_FOUND => min($ttl, (int) ($cacheConfig['not_found_ttl'] ?? 900)),
                AeoResponse::STATUS_SERVER_ERROR => $this->backoffTtlForFailures(
                    $cacheConfig,
                    $this->bumpFailureCount($repository, $cacheKey),
                ),
                AeoResponse::STATUS_PENDING => (int) ($cacheConfig['pending_ttl'] ?? 15),
                default => $ttl,
            };
            $repository->put($cacheKey, $response, $writeTtl);

            // Trip the AEO surface circuit on transport / 5xx failures
            // so the next distinct URL in this same outage window short-
            // circuits. v0.7.0 round-4: only the AEO surface, never markdown.
            if ($response->status === AeoResponse::STATUS_SERVER_ERROR) {
                $this->tripCircuit($repository, 'aeo');
            }

            // v0.7.1: half-open success → emit "circuit closed" log if
            // we just recovered from an outage. Only checked on a ready
            // response; tombstone is atomic-pulled so concurrent half-
            // open requests log at most once. v0.10.0: also clears the
            // failure counter so the next outage starts at 30s, not at
            // whatever stage a long-ago failure left it.
            if ($response->status === AeoResponse::STATUS_READY) {
                $this->resetFailureCount($repository, $cacheKey);
                if (! $this->circuitOpen($repository, 'aeo')) {
                    $this->maybeLogCircuitClosed($repository, 'aeo');
                }
            }
        });
    }

    /**
     * Per-surface circuit breaker (v0.7.0 round-3 → round-4). Tripped by
     * writing a short-lived flag in the cache; checked at the start of each
     * forPath() / getMarkdown() call against its own surface key. While the
     * flag is present the SDK skips the upstream entirely for THAT surface,
     * no matter which path is being requested.
     *
     * v0.7.0 round-4: split into 'aeo' (HTML AEO) and 'md' (markdown for
     * agents) keys. Markdown is an agent-only optional surface — if it's
     * unhealthy it should not suppress HTML injection, which serves every
     * page render. Each surface tracks its own breaker and recovers
     * independently.
     *
     * Auto half-open: the flag has TTL = `circuit_breaker_ttl` (default
     * 60s). When it expires, the next request to that surface hits upstream
     * — if that succeeds, the breaker stays closed. If it fails again, the
     * breaker trips for another 60s.
     *
     * Same `(api_key, base_url)` namespace as the rest of the cache, so
     * rotating either auto-clears the breaker for both surfaces.
     *
     * @param  'aeo'|'md'  $surface
     */
    private function circuitKey(string $surface): string
    {
        $cacheConfig = $this->config->get('smking.cache', []);

        return ($cacheConfig['circuit_prefix'] ?? 'smking:circuit:').$surface.':'.$this->cacheNamespace();
    }

    /**
     * @param  'aeo'|'md'  $surface
     */
    private function circuitOpen(\Illuminate\Contracts\Cache\Repository $repository, string $surface): bool
    {
        $cacheConfig = $this->config->get('smking.cache', []);
        if (! ($cacheConfig['circuit_breaker'] ?? true)) {
            return false;
        }

        return $repository->has($this->circuitKey($surface));
    }

    /**
     * @param  'aeo'|'md'  $surface
     * @return bool True when this call opened the circuit (or the breaker is
     *              disabled), false when another request already opened it.
     */
    private function tripCircuit(\Illuminate\Contracts\Cache\Repository $repository, string $surface): bool
    {
        $cacheConfig = $this->config->get('smking.cache', []);
        if (! ($cacheConfig['circuit_breaker'] ?? true)) {
            return true;
        }

        $key = $this->circuitKey($surface);
        $ttl = (int) ($cacheConfig['circuit_breaker_ttl'] ?? 60);

        // Select the one cold-retry owner atomically. A separate has()+put()
        // lets synchronized PHP-FPM workers all observe a missing key before
        // any of them writes it, so every worker can incorrectly retry. The
        // shared cache drivers implement add() as an atomic create-if-absent.
        $opened = $repository->add($key, true, $ttl);

        // Keep the existing sliding outage window: failures arriving while
        // the breaker is open extend its TTL, but they do not gain retry
        // ownership. ArrayStore's fallback is process-local, where there is
        // no cross-process claim race to arbitrate.
        if (! $opened) {
            $repository->put($key, true, $ttl);
        }

        // v0.7.1: refresh the tombstone on every trip (NOT gated on
        // alreadyOpen) so a long outage extending past the original
        // tombstone TTL still has a valid tombstone for the eventual
        // half-open recovery to pull. Tombstone TTL is 5× breaker TTL
        // so a brief network blip doesn't leave a stale recovery flag.
        $repository->put($this->circuitTombstoneKey($surface), true, $ttl * 5);

        // v0.7.1 observability: log only on the first trip of an outage
        // window, not on every re-trip while the breaker is already open.
        // An outage that produces 1000 failures should produce 1 log line,
        // not 1000 — operators page on the trip event, not the steady-state.
        // The breaker auto-resets when its TTL expires, so the next trip
        // after recovery gets its own log line.
        if ($opened) {
            $this->logger?->warning("smking: circuit breaker tripped for {$surface} surface", [
                'surface' => $surface,
                'ttl_seconds' => $ttl,
                'key' => $key,
            ]);
        }

        // The first transport failure owns the single cold-start retry.
        // In-flight requests that fail after the breaker is already open
        // must stop immediately instead of each spending the long retry.
        return $opened;
    }

    /**
     * v0.7.1: half-open recovery detection. Called on a successful upstream
     * response to check whether this surface had previously tripped — if so,
     * the tombstone is atomically pulled (only one concurrent recovery wins
     * the pull) and a `circuit closed` info log is emitted.
     *
     * Atomic pull is what guarantees at-most-one log per recovery cycle:
     * Laravel's `Cache::pull()` is a get-and-forget. Concurrent recovery
     * requests after breaker TTL expiry race for the tombstone — the
     * winner gets a non-null value and logs; losers get null and skip.
     *
     * @param  'aeo'|'md'  $surface
     */
    private function maybeLogCircuitClosed(\Illuminate\Contracts\Cache\Repository $repository, string $surface): void
    {
        $cacheConfig = $this->config->get('smking.cache', []);
        if (! ($cacheConfig['circuit_breaker'] ?? true)) {
            return;
        }

        if ($repository->pull($this->circuitTombstoneKey($surface)) !== null) {
            $this->logger?->info("smking: circuit closed for {$surface} surface", [
                'surface' => $surface,
            ]);
        }
    }

    /**
     * Tombstone key is keyed off the same circuit-key prefix and surface so
     * cache-purge / namespace rotation reaches it the same way.
     *
     * @param  'aeo'|'md'  $surface
     */
    private function circuitTombstoneKey(string $surface): string
    {
        return $this->circuitKey($surface).':tombstone';
    }

    /**
     * v0.10.0: adaptive backoff TTL for `server_error` cache entries. Lookup
     * is keyed by the **consecutive failure count for this specific cache
     * key** (counter at {@see failureCountKey()}), so each path's backoff
     * progresses independently and `cache:purge` resets the counter along
     * with the cache value.
     *
     * Default steps (configurable via `smking.cache.server_error_backoff`):
     *   1st failure → 30s    — auto-recovers from install typos / temporary
     *                          DNS hiccups in seconds
     *   2nd failure → 5 min  — defends against tight-loop retry while still
     *                          surfacing within a coffee break
     *   3rd failure → 30 min — gives operator a window to react
     *   4th+        → fallback to `server_error_ttl` (default 24hr) for
     *                          steady-state outage protection
     *
     * Set `server_error_backoff => []` or `false` to disable backoff and
     * use the pre-v0.10.0 flat-24hr behavior.
     *
     * @param  array<string, mixed>  $cacheConfig
     */
    private function backoffTtlForFailures(array $cacheConfig, int $failureCount): int
    {
        $fallbackTtl = (int) ($cacheConfig['server_error_ttl'] ?? 86400);
        $steps = $cacheConfig['server_error_backoff'] ?? [30, 300, 1800];

        if ($steps === false || ! is_array($steps) || $steps === []) {
            return $fallbackTtl;
        }

        // 1-indexed: failure #1 reads $steps[0]. Out-of-range fails over to
        // fallback so config-supplied steps shorter than 4 still behave.
        $index = max(0, $failureCount - 1);
        if ($index >= count($steps)) {
            return $fallbackTtl;
        }

        $ttl = $steps[$index];
        return is_numeric($ttl) ? (int) $ttl : $fallbackTtl;
    }

    /**
     * Counter key for a given cache key. Suffixing the cache key directly
     * (rather than introducing a separate prefix) means namespace rotation
     * (`api_key` / `base_url` change) and `cache:purge <path>` automatically
     * invalidate the counter alongside the cached value — no separate
     * cleanup paths to keep in sync.
     */
    private function failureCountKey(string $cacheKey): string
    {
        return $cacheKey.':fc';
    }

    /**
     * Increment and persist the consecutive-failure counter for a cache key.
     * Returns the new count.
     *
     * Counter TTL is 30 days — long enough to outlast any realistic outage
     * window but bounded so a permanently-broken key (deleted site, stale
     * URL never visited again) doesn't accumulate counter state forever.
     */
    private function bumpFailureCount(\Illuminate\Contracts\Cache\Repository $repository, string $cacheKey): int
    {
        $key = $this->failureCountKey($cacheKey);
        $current = $repository->get($key);
        $next = (is_int($current) ? $current : 0) + 1;
        $repository->put($key, $next, 30 * 86400);

        return $next;
    }

    /**
     * Clear the consecutive-failure counter for a cache key. Called on
     * `ready` responses so the next outage starts at the 30s backoff step
     * instead of whatever step a long-ago failure left behind.
     */
    private function resetFailureCount(\Illuminate\Contracts\Cache\Repository $repository, string $cacheKey): void
    {
        $repository->forget($this->failureCountKey($cacheKey));
    }

    /**
     * Single-flight wrapper around the upstream call. Exactly one concurrent
     * worker gets the lock and does the network fetch + cache write; others
     * return a fail-open response (notFound — middleware behavior identical
     * to "we have no content for this path") so they don't block on the
     * upstream.
     *
     * Lock contention is not an error — it just means another worker is
     * doing the work. The lock TTL covers every enabled HTTP attempt plus a
     * small release margin, so it cannot expire while a cold-start retry is
     * still running. It remains bounded so a crashed worker cannot
     * permanently block the key.
     *
     * @param  callable(): AeoResponse  $resolver  upstream call (only the
     *         lock-holder runs this)
     * @param  callable(AeoResponse): void  $writer  write the result to
     *         cache with the right TTL (only runs after a successful lock)
     */
    private function singleFlight(
        \Illuminate\Contracts\Cache\Repository $repository,
        string $cacheKey,
        callable $resolver,
        callable $writer,
    ): AeoResponse {
        // Detect lock support on the *underlying* store. Laravel's
        // `Repository` wrapper proxies unknown methods to its store via
        // `__call`, so `method_exists($repository, 'lock')` returns false
        // even when the configured store (redis / memcached / database /
        // array / file / dynamodb) does support atomic locks. The right
        // check is `instanceof LockProvider` against the store itself.
        if (! $this->supportsLock($repository)) {
            // No lock support — fall back to plain fetch+write. Race is
            // possible but worst case is N redundant upstream calls
            // (same as pre-v0.7.0 behavior).
            $response = $resolver();
            $writer($response);

            return $response;
        }

        $lockKey = $cacheKey.':lock';
        $requestBudget = $this->readTimeout() + $this->connectTimeout();
        if ($this->coldStartRetryEnabled()) {
            $requestBudget += $this->coldStartTimeout() + $this->coldStartConnectTimeout();
        }
        $lockTtl = max(5, (int) ceil($requestBudget + 2));

        // Repository::__call routes lock() to the store, which is fine —
        // we already gated on supportsLock() above.
        $lock = $repository->lock($lockKey, $lockTtl);
        if (! $lock->get()) {
            // Another worker is already calling upstream. Fail open — return
            // notFound so this worker's response goes back to the user
            // immediately. Next request to this path (any worker) will hit
            // cache once the lock-holder finishes.
            return AeoResponse::notFound();
        }

        try {
            // Re-check cache after acquiring lock — between get() and lock()
            // another worker may have already populated it.
            $cached = $repository->get($cacheKey);
            if ($cached instanceof AeoResponse) {
                return $cached;
            }

            $response = $resolver();
            $writer($response);

            return $response;
        } finally {
            $lock->release();
        }
    }

    /**
     * Fetch a managed file (sitemap.xml / robots.txt / llms.txt) from the
     * smking SaaS for path-takeover. Used by InjectAeo middleware when the
     * customer's own app would 404 on that path.
     *
     * Cached separately from forPath() / getMarkdown() — different value type
     * + different TTL strategy. Daily TTL by default (files don't change
     * second-to-second). On any failure returns null so middleware falls
     * back to the original customer response (don't break the site).
     *
     * @param  'sitemap'|'robots'|'llms_txt'  $kind
     * @return array{body: string, contentType: string}|null
     */
    public function fetchPublicFile(string $kind): ?array
    {
        $apiKey = $this->apiKey();
        if ($apiKey === null || $this->baseUrl() === null) {
            return null;
        }

        $endpoint = match ($kind) {
            'sitemap' => '/api/v1/public/sitemap.xml',
            'robots' => '/api/v1/public/robots.txt',
            'llms_txt' => '/api/v1/public/llms-txt',
            default => null,
        };
        if ($endpoint === null) {
            return null;
        }

        $cacheConfig = $this->config->get('smking.cache', []);
        $enabled = (bool) ($cacheConfig['enabled'] ?? true);
        $store = $cacheConfig['store'] ?? null;
        $repository = $store ? $this->cache->store($store) : $this->cache->store();

        $cacheKey = 'smking:takeover:'.$this->cacheNamespace().':'.$kind;
        $ttl = (int) ($cacheConfig['takeover_ttl'] ?? 86400); // 24h default

        if ($enabled && $ttl > 0) {
            $cached = $repository->get($cacheKey);
            if (is_array($cached) && isset($cached['body'], $cached['contentType'])) {
                return $cached;
            }
            // Cached miss → don't re-fetch every request when SaaS already said
            // "nope". Short TTL on misses so customers see fixes promptly.
            if ($cached === false) {
                return null;
            }
        }

        try {
            $response = $this->http
                ->connectTimeout($this->httpTimeoutInt($this->connectTimeout()))
                ->timeout($this->httpTimeoutInt($this->readTimeout()))
                ->get($this->endpoint($endpoint), ['key' => $apiKey]);
        } catch (Throwable $e) {
            $this->logger?->warning('smking: takeover fetch failed', [
                'kind' => $kind,
                'message' => $e->getMessage(),
            ]);
            if ($enabled && $ttl > 0) {
                $missTtl = min($ttl, (int) ($cacheConfig['not_found_ttl'] ?? 900));
                $repository->put($cacheKey, false, $missTtl);
            }

            return null;
        }

        if (! $response->successful()) {
            if ($enabled && $ttl > 0) {
                $missTtl = $response->status() >= 500
                    ? (int) ($cacheConfig['server_error_ttl'] ?? 86400)
                    : min($ttl, (int) ($cacheConfig['not_found_ttl'] ?? 900));
                $repository->put($cacheKey, false, $missTtl);
            }

            return null;
        }

        $result = [
            'body' => $response->body(),
            'contentType' => (string) $response->header('Content-Type') ?: $this->defaultContentTypeFor($kind),
        ];

        if ($enabled && $ttl > 0) {
            $repository->put($cacheKey, $result, $ttl);
        }

        return $result;
    }

    private function defaultContentTypeFor(string $kind): string
    {
        return match ($kind) {
            'sitemap' => 'application/xml; charset=utf-8',
            'robots', 'llms_txt' => 'text/plain; charset=utf-8',
            default => 'text/plain; charset=utf-8',
        };
    }

    private function apiKey(): ?string
    {
        $key = $this->config->get('smking.api_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    private function baseUrl(): ?string
    {
        $value = $this->config->get('smking.base_url');

        return is_string($value) && $value !== '' ? rtrim($value, '/') : null;
    }

    /**
     * SDK self-report metadata piggybacked on every AEO POST. Saas uses this
     * to upsert {@see sdk_health_snapshots} with source='sdk_heartbeat' so
     * the dashboard can show "SDK active (last seen Nm ago)" without an
     * outbound probe — necessary because customer Cloudflare WAFs routinely
     * block saas's Vercel ASN egress.
     *
     * All fields are nullable on the saas side. Host pull is defensive
     * (`request()` is not bound in queue workers / artisan commands), and
     * `InstalledVersions::isInstalled` check covers the rare case where
     * Composer's runtime API is unavailable (e.g. raw script include).
     */
    private function selfReport(array $telemetryEvents = []): array
    {
        $cacheConfig = $this->config->get('smking.cache', []);
        $configuredStore = $cacheConfig['store'] ?? $this->config->get('cache.default');
        $report = [
            'sdk' => 'laravel',
            'sdk_version' => InstalledVersions::isInstalled('smking/laravel')
                ? InstalledVersions::getVersion('smking/laravel')
                : null,
            'app_env' => is_string($env = $this->config->get('app.env')) ? $env : null,
            'host' => $this->safeHost(),
            // Site-level CMS base path — lets the smking dashboard build
            // "View page" URLs (domain + base path + slug) without per-page
            // visits. Default "/blog" via config; null only if explicitly unset.
            'cms_base_path' => is_string($cb = $this->config->get('smking.cms.base_path'))
                ? $cb
                : null,
            'cache_store' => is_string($configuredStore) && $configuredStore !== ''
                ? $configuredStore
                : null,
            'cache_enabled' => (bool) ($cacheConfig['enabled'] ?? true),
            'ready_ttl_seconds' => max(0, (int) ($cacheConfig['ttl'] ?? 3600)),
            'not_found_ttl_seconds' => max(0, (int) ($cacheConfig['not_found_ttl'] ?? 60)),
            'circuit_breaker_enabled' => (bool) ($cacheConfig['circuit_breaker'] ?? true),
            'circuit_breaker_ttl_seconds' => max(0, (int) ($cacheConfig['circuit_breaker_ttl'] ?? 60)),
            'cold_start_retry_enabled' => $this->coldStartRetryEnabled(),
            'connect_timeout_ms' => max(0, (int) round($this->connectTimeout() * 1000)),
            'read_timeout_ms' => max(0, (int) round($this->readTimeout() * 1000)),
        ];

        if ($telemetryEvents !== []) {
            $report['telemetry_events'] = $telemetryEvents;
        }

        return $report;
    }

    /**
     * Build the privacy-safe event shared by the local structured logger and
     * the delayed SaaS telemetry outbox. Deliberately excludes path, URL, API
     * key, exception text, headers, and response bodies.
     *
     * @param  list<string>  $attemptOutcomes
     * @param  list<int>  $httpStatuses
     * @return array<string, mixed>
     */
    private function discoveryEvent(
        string $requestId,
        array $attemptOutcomes,
        array $httpStatuses,
        string $circuitAction,
        string $finalStatus,
        int $durationMs,
    ): array {
        return [
            'event' => 'aeo_discovery',
            'event_id' => $this->opaqueId(),
            'request_id' => $requestId,
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'attempt_count' => count($attemptOutcomes),
            'attempt_outcomes' => array_values($attemptOutcomes),
            'http_statuses' => array_values($httpStatuses),
            'circuit_action' => $circuitAction,
            'final_status' => $finalStatus,
            'duration_ms' => min(120000, max(0, $durationMs)),
        ];
    }

    private function recordShortCircuit(): void
    {
        $event = $this->discoveryEvent(
            requestId: $this->opaqueId(),
            attemptOutcomes: [],
            httpStatuses: [],
            circuitAction: 'short_circuited',
            finalStatus: AeoResponse::STATUS_SERVER_ERROR,
            durationMs: 0,
        );
        $this->logger?->info('smking: AEO discovery', $event);
        $this->appendTelemetryEvent($event);
    }

    private function circuitProtectionEnabled(): bool
    {
        $cacheConfig = $this->config->get('smking.cache', []);

        return (bool) ($cacheConfig['enabled'] ?? true)
            && (bool) ($cacheConfig['circuit_breaker'] ?? true);
    }

    private function transportOutcome(Throwable $error): string
    {
        $message = strtolower($error->getMessage());

        return str_contains($message, 'timeout')
            || str_contains($message, 'timed out')
            || str_contains($message, 'curl error 28')
            ? 'timeout'
            : 'transport_error';
    }

    private function opaqueId(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (Throwable) {
            return substr(hash('sha256', uniqid('', true).microtime(true)), 0, 32);
        }
    }

    /** @return list<array<string, mixed>> */
    private function pendingTelemetryEvents(): array
    {
        try {
            $events = $this->cacheStore()->get($this->telemetryOutboxKey(), []);
            if (! is_array($events)) {
                return [];
            }

            $valid = array_values(array_filter(
                $events,
                static fn ($event): bool => is_array($event)
                    && isset($event['event_id'])
                    && is_string($event['event_id'])
                    && preg_match('/^[a-f0-9]{32}$/', $event['event_id']) === 1,
            ));

            return array_slice($valid, -self::TELEMETRY_OUTBOX_MAX_EVENTS);
        } catch (Throwable) {
            // Telemetry is best-effort and must never affect a page render.
            return [];
        }
    }

    /** @param array<string, mixed> $event */
    private function appendTelemetryEvent(array $event): void
    {
        $this->mutateTelemetryOutbox(static function (array $events) use ($event): array {
            $events[] = $event;

            return array_slice($events, -self::TELEMETRY_OUTBOX_MAX_EVENTS);
        });
    }

    /** @param list<array<string, mixed>> $sentEvents */
    private function acknowledgeTelemetryEvents(array $sentEvents): void
    {
        $sentIds = array_fill_keys(array_values(array_filter(array_map(
            static fn (array $event): ?string => isset($event['event_id']) && is_string($event['event_id'])
                ? $event['event_id']
                : null,
            $sentEvents,
        ))), true);

        if ($sentIds === []) {
            return;
        }

        $this->mutateTelemetryOutbox(static fn (array $events): array => array_values(array_filter(
            $events,
            static fn (array $event): bool => ! isset($sentIds[$event['event_id'] ?? '']),
        )));
    }

    /**
     * @param callable(list<array<string, mixed>>): list<array<string, mixed>> $mutator
     */
    private function mutateTelemetryOutbox(callable $mutator): void
    {
        try {
            $repository = $this->cacheStore();
            $key = $this->telemetryOutboxKey();
            $write = function () use ($repository, $key, $mutator): void {
                $current = $repository->get($key, []);
                $events = is_array($current)
                    ? array_values(array_filter($current, 'is_array'))
                    : [];
                $next = array_slice($mutator($events), -self::TELEMETRY_OUTBOX_MAX_EVENTS);

                if ($next === []) {
                    $repository->forget($key);
                } else {
                    $repository->put($key, $next, self::TELEMETRY_OUTBOX_TTL_SECONDS);
                }
            };

            if (! $this->supportsLock($repository)) {
                $write();

                return;
            }

            $lock = $repository->lock($key.':lock', 5);
            if (! $lock->get()) {
                return;
            }

            try {
                $write();
            } finally {
                $lock->release();
            }
        } catch (Throwable) {
            // Best-effort only: cache/lock outages must not touch host pages.
        }
    }

    private function telemetryOutboxKey(): string
    {
        return 'smking:telemetry:'.$this->cacheNamespace();
    }

    /**
     * Best-effort HTTP host extraction. Returns null when no request scope
     * is bound (queue / artisan / tests without HTTP mock).
     */
    private function safeHost(): ?string
    {
        try {
            if (function_exists('request')) {
                $req = request();
                if ($req !== null) {
                    return $req->getHttpHost();
                }
            }
        } catch (Throwable) {
            // No request scope — fall through to null.
        }

        return null;
    }

    /**
     * 12-char hash of (api_key, base_url). Stable across requests for the
     * same env, changes when either rotates so old cache entries auto-evict.
     * Public so {@see Support\AeoCacheInvalidator} can reconstruct the key
     * prefixes without reaching into private state.
     *
     * @internal exposed for the shared cache invalidator; not part of public API.
     */
    public function cacheNamespace(): string
    {
        return substr(
            hash('sha256', ($this->apiKey() ?? '').'|'.($this->baseUrl() ?? '')),
            0,
            12,
        );
    }

    /**
     * Cache key prefixes / breaker keys used by this client. Used by the
     * shared cache invalidator to flush per-path entries AND the
     * surface-scoped circuit breakers in one shot.
     *
     *   aeo        — `smking:aeo:{ns}:`           (forPath / forProductId)
     *   markdown   — `smking:md:{ns}:`            (getMarkdown)
     *   circuit_aeo — `smking:circuit:aeo:{ns}`   (HTML AEO breaker, full key)
     *   circuit_md  — `smking:circuit:md:{ns}`    (markdown breaker, full key)
     *
     * v0.7.0 round-4: breaker keys split per upstream surface so a markdown
     * outage doesn't suppress the customer-facing HTML injection path.
     *
     * @return array{aeo: string, markdown: string, circuit_aeo: string, circuit_md: string}
     */
    public function cacheKeyPrefixes(): array
    {
        $cacheConfig = $this->config->get('smking.cache', []);
        $ns = $this->cacheNamespace();
        $circuitPrefix = $cacheConfig['circuit_prefix'] ?? 'smking:circuit:';

        return [
            'aeo' => ($cacheConfig['prefix'] ?? 'smking:aeo:').$ns.':',
            'markdown' => ($cacheConfig['markdown_prefix'] ?? 'smking:md:').$ns.':',
            'circuit_aeo' => $circuitPrefix.'aeo:'.$ns,
            'circuit_md' => $circuitPrefix.'md:'.$ns,
        ];
    }

    public function cacheStore(): \Illuminate\Contracts\Cache\Repository
    {
        $store = $this->config->get('smking.cache.store');

        return $store ? $this->cache->store($store) : $this->cache->store();
    }

    private function endpoint(string $path): string
    {
        return ((string) $this->baseUrl()).$path;
    }
}
