<?php

declare(strict_types=1);

namespace Smking\Laravel;

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
        return $this->remember(['path' => $path], function () use ($path, $url) {
            return $this->discover(['path' => $path, 'url' => $url]);
        });
    }

    public function forProductId(int $productId): AeoResponse
    {
        return $this->remember(['product_id' => $productId], function () use ($productId) {
            return $this->discover(['product_id' => $productId]);
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
                ->connectTimeout($this->connectTimeout())
                ->timeout($this->readTimeout())
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
                $writeTtl = $result['status'] === AeoResponse::STATUS_SERVER_ERROR
                    ? (int) ($cacheConfig['server_error_ttl'] ?? 86400)
                    : min($ttl, (int) ($cacheConfig['not_found_ttl'] ?? 900));
                $repository->put($cacheKey, false, $writeTtl);

                return;
            }
            $repository->put($cacheKey, $result['body'], $ttl);
        };

        if (! method_exists($repository, 'lock')) {
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
     */
    private function discover(array $body): AeoResponse
    {
        $apiKey = $this->apiKey();
        if ($apiKey === null) {
            return AeoResponse::notFound();
        }

        if ($this->baseUrl() === null) {
            $this->logger?->warning('smking: SMKING_BASE_URL is not configured; set it in your .env to enable AEO discovery.');

            return AeoResponse::notFound();
        }

        $payload = array_filter(
            array_merge(['key' => $apiKey], $body),
            static fn ($value) => $value !== null && $value !== '',
        );

        try {
            $response = $this->http
                ->connectTimeout($this->connectTimeout())
                ->timeout($this->readTimeout())
                ->acceptJson()
                ->asJson()
                ->post($this->endpoint('/api/v1/public/aeo'), $payload);
        } catch (Throwable $e) {
            // DNS / TCP / read timeout / connection errors → SaaS effectively
            // unreachable. v0.7.0: treat as server_error so cache TTL is 24hr
            // (vs not_found 15min), saving a million-PV site from retrying
            // a dead upstream every 30 seconds.
            $this->logger?->warning('smking: AEO discovery failed', [
                'message' => $e->getMessage(),
                'body' => $body,
            ]);

            return AeoResponse::serverError();
        }

        if ($response->status() === 202) {
            return AeoResponse::pending();
        }

        if (! $response->successful()) {
            // 5xx → SaaS broken on its end → server_error (24hr cache).
            // 4xx → SaaS rejected the request (bad key / unknown path) →
            // not_found (15min cache, lets backend audit catch up).
            if ($response->status() >= 500) {
                $this->logger?->warning('smking: AEO discovery upstream 5xx', [
                    'status' => $response->status(),
                    'body' => $body,
                ]);

                return AeoResponse::serverError();
            }

            return AeoResponse::notFound();
        }

        $json = $response->json();
        if (! is_array($json)) {
            return AeoResponse::notFound();
        }

        return AeoResponse::fromArray($json);
    }

    /**
     * Resolve `cache.connect_timeout` from config; default 1.0s. Floats
     * accepted because Laravel's HTTP client supports sub-second precision.
     */
    private function connectTimeout(): float
    {
        $value = $this->config->get('smking.connect_timeout', 1.0);

        return is_numeric($value) ? (float) $value : 1.0;
    }

    /**
     * Resolve `smking.timeout` (read timeout) from config; default 1.5s
     * since v0.7.0 (was 3s). Floats accepted.
     */
    private function readTimeout(): float
    {
        $value = $this->config->get('smking.timeout', 1.5);

        return is_numeric($value) ? (float) $value : 1.5;
    }

    /**
     * @param  array<string, scalar>  $keyParts
     * @param  callable(): AeoResponse  $resolver
     */
    private function remember(array $keyParts, callable $resolver): AeoResponse
    {
        $cacheConfig = $this->config->get('smking.cache', []);
        $enabled = (bool) ($cacheConfig['enabled'] ?? true);
        $ttl = (int) ($cacheConfig['ttl'] ?? 3600);

        if (! $enabled || $ttl <= 0) {
            return $resolver();
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

        // v0.7.0 single-flight protection: under concurrent traffic to a
        // cold key, all workers would otherwise call upstream simultaneously
        // (cache stampede / thundering herd). One worker takes a short lock
        // and does the upstream fetch + cache write; others fail open so
        // worker pool stays healthy.
        return $this->singleFlight($repository, $cacheKey, $resolver, function (AeoResponse $response) use ($repository, $cacheKey, $cacheConfig, $ttl): void {
            // Don't cache pending — recheck on next request.
            if ($response->status === AeoResponse::STATUS_PENDING) {
                return;
            }

            // v0.7.0 three-tier TTL:
            //   ready        → full ttl (default 1hr)
            //   not_found    → not_found_ttl (default 15min, was 30s)
            //   server_error → server_error_ttl (default 24hr) — kills retry
            //                  loop against a dead upstream so million-PV
            //                  sites don't saturate FPM workers
            $writeTtl = match ($response->status) {
                AeoResponse::STATUS_READY => $ttl,
                AeoResponse::STATUS_NOT_FOUND => min($ttl, (int) ($cacheConfig['not_found_ttl'] ?? 900)),
                AeoResponse::STATUS_SERVER_ERROR => (int) ($cacheConfig['server_error_ttl'] ?? 86400),
                default => $ttl,
            };
            $repository->put($cacheKey, $response, $writeTtl);
        });
    }

    /**
     * Single-flight wrapper around the upstream call. Exactly one concurrent
     * worker gets the lock and does the network fetch + cache write; others
     * return a fail-open response (notFound — middleware behavior identical
     * to "we have no content for this path") so they don't block on the
     * upstream.
     *
     * Lock contention is not an error — it just means another worker is
     * doing the work. The lock TTL is short (slightly longer than the read
     * timeout) so a crashed worker doesn't permanently block the key.
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
        if (! method_exists($repository, 'lock')) {
            // Cache driver doesn't support locks (file driver in some
            // versions). Fall back to plain fetch+write — race is possible
            // but the worst case is N redundant upstream calls (same as
            // pre-v0.7.0 behavior).
            $response = $resolver();
            $writer($response);

            return $response;
        }

        $lockKey = $cacheKey.':lock';
        $lockTtl = max(5, (int) ceil($this->readTimeout() + $this->connectTimeout() + 2));

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
     * 12-char hash of (api_key, base_url). Stable across requests for the
     * same env, changes when either rotates so old cache entries auto-evict.
     * Public so {@see Console\CachePurgeCommand} can reconstruct the key
     * prefixes without reaching into private state.
     *
     * @internal exposed for the cache-purge command; not part of public API.
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
     * Both cache key prefixes used by this client: `smking:aeo:{ns}:` for
     * forPath() and `smking:md:{ns}:` for getMarkdown(). Used by the
     * cache-purge command to flush both surfaces in one shot.
     *
     * @return array{aeo: string, markdown: string}
     */
    public function cacheKeyPrefixes(): array
    {
        $cacheConfig = $this->config->get('smking.cache', []);
        $ns = $this->cacheNamespace();

        return [
            'aeo' => ($cacheConfig['prefix'] ?? 'smking:aeo:').$ns.':',
            'markdown' => ($cacheConfig['markdown_prefix'] ?? 'smking:md:').$ns.':',
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
