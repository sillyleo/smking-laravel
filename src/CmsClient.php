<?php

declare(strict_types=1);

namespace Smking\Laravel;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Log\LoggerInterface;
use Smking\Laravel\Data\CmsPage;
use Smking\Laravel\Tiptap\EditorFactory;
use Throwable;

/**
 * Thin client for the smking Public CMS API.
 *
 * Mirrors AeoClient's shape but **deliberately simpler** for v1: just
 * cache + fail-open + tiptap render. The 918-line single-flight /
 * circuit breaker / adaptive backoff hardening from AeoClient gets
 * ported in Phase 2 once we see real traffic patterns. For now the
 * defence is:
 *   - 5min cache TTL (CMS edits are more frequent than AEO crawl
 *     output, so shorter TTL than AEO's 1h default)
 *   - timeout-protected HTTP call
 *   - any failure → CmsPage::serverError() / notFound() so middleware
 *     / Blade falls through to the host's own response
 */
class CmsClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly CacheFactory $cache,
        private readonly ConfigRepository $config,
        private readonly EditorFactory $editor,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Fetch + render the published page for the given slug.
     */
    public function forSlug(string $slug): CmsPage
    {
        return $this->remember($slug, fn (): CmsPage => $this->fetch($slug));
    }

    private function fetch(string $slug): CmsPage
    {
        $apiKey = $this->apiKey();
        if ($apiKey === null) {
            return CmsPage::notFound();
        }

        if ($this->baseUrl() === null) {
            $this->logger?->warning('smking: SMKING_BASE_URL is not configured; set it in your .env to enable CMS rendering.');
            return CmsPage::notFound();
        }

        try {
            $response = $this->http
                ->connectTimeout($this->httpTimeoutInt($this->connectTimeout()))
                ->timeout($this->httpTimeoutInt($this->readTimeout()))
                ->acceptJson()
                ->get($this->endpoint('/api/v1/public/page'), [
                    'key' => $apiKey,
                    'slug' => $slug,
                ]);
        } catch (Throwable $e) {
            $this->logger?->warning('smking: CMS fetch failed', [
                'message' => $e->getMessage(),
                'slug' => $slug,
            ]);
            return CmsPage::serverError();
        }

        if (! $response->successful()) {
            return $response->status() >= 500
                ? CmsPage::serverError()
                : CmsPage::notFound();
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            return CmsPage::notFound();
        }

        $status = (string) ($payload['status'] ?? CmsPage::STATUS_NOT_FOUND);
        if ($status !== CmsPage::STATUS_READY) {
            return new CmsPage(status: $status);
        }

        $page = $payload['page'] ?? null;
        if (! is_array($page)) {
            return CmsPage::notFound();
        }

        // v0.14.0+ substrate v2: SaaS public API returns `page.blocks`
        // (Block[]) instead of the legacy `page.body` (Tiptap ProseMirror
        // JSON). We accept both — `blocks` wins when present so customer
        // SDKs see new substrate output; `body` is kept as a fallback so
        // an older SaaS deployment (pre-pivot) doesn't break the SDK.
        if (isset($page['blocks']) && is_array($page['blocks'])) {
            return CmsPage::fromArray($payload, blocks: $page['blocks']);
        }

        if (isset($page['body'])) {
            // Legacy path — Tiptap ProseMirror JSON. EditorFactory
            // renders it server-side; malformed docs come back as ''.
            $bodyHtml = $this->editor->renderHtml($page['body']);
            return CmsPage::fromArray($payload, bodyHtml: $bodyHtml);
        }

        return CmsPage::notFound();
    }

    private function remember(string $slug, callable $resolver): CmsPage
    {
        $cacheConfig = $this->config->get('smking.cache', []);
        $enabled = (bool) ($cacheConfig['enabled'] ?? true);
        $ttl = (int) ($cacheConfig['cms_ttl'] ?? 300);

        if (! $enabled || $ttl <= 0) {
            return $resolver();
        }

        $store = $cacheConfig['store'] ?? null;
        $repository = $store ? $this->cache->store($store) : $this->cache->store();

        $cacheKey = ($cacheConfig['cms_prefix'] ?? 'smking:cms:').$this->cacheNamespace().':'.$slug;

        $cached = $repository->get($cacheKey);
        if ($cached instanceof CmsPage) {
            return $cached;
        }

        $page = $resolver();

        // Successful renders cache for full ttl; failures cache short
        // so a transient cold-start timeout doesn't lock out for the full
        // 5min cache window. CMS surface uses its own short TTLs (15s
        // default) instead of inheriting AEO's 60s — CMS is cold-path,
        // failures are usually first-hit cold start that succeeds on the
        // very next attempt.
        $writeTtl = match ($page->status) {
            CmsPage::STATUS_READY => $ttl,
            CmsPage::STATUS_NOT_FOUND => min($ttl, (int) ($cacheConfig['cms_not_found_ttl'] ?? 15)),
            CmsPage::STATUS_SERVER_ERROR => min($ttl, (int) ($cacheConfig['cms_server_error_ttl'] ?? 15)),
            default => $ttl,
        };
        $repository->put($cacheKey, $page, $writeTtl);

        return $page;
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

    private function endpoint(string $path): string
    {
        return ((string) $this->baseUrl()).$path;
    }

    /**
     * CMS surface uses generous timeout defaults vs AEO. Reasoning:
     * AEO is auto-injected on every page load → Vercel functions stay
     * warm → 1.5s read covers steady-state. CMS fetch is rare (only
     * when a customer page renders the <x-smking-cms> component, and
     * we cache for 5 min) → Vercel cold starts dominate the first
     * call per cache cycle, easily 3-5s. A 1.5s timeout here means
     * every cache miss times out → server_error → user sees blank.
     *
     * Defaults: 3s connect / 10s read. Override per-env:
     *   SMKING_CMS_CONNECT_TIMEOUT, SMKING_CMS_TIMEOUT
     *
     * Self-hosted SaaS customers running their own Next.js with
     * always-on workers can drop these back to AEO levels for tighter
     * latency budgets.
     */
    private function connectTimeout(): float
    {
        $value = $this->config->get('smking.cms_connect_timeout', 3.0);
        return is_numeric($value) ? (float) $value : 3.0;
    }

    private function readTimeout(): float
    {
        $value = $this->config->get('smking.cms_timeout', 10.0);
        return is_numeric($value) ? (float) $value : 10.0;
    }

    /**
     * Laravel 11+ tightened HTTP client to int seconds. Mirror AeoClient's
     * httpTimeoutInt(): ceil so 1.5s → 2s (never shorter than configured).
     */
    private function httpTimeoutInt(float $seconds): int
    {
        return max(1, (int) ceil($seconds));
    }

    /**
     * 12-char hash of (api_key, base_url) — same scheme as AeoClient so
     * cache:purge can flush both surfaces together via the same namespace.
     */
    public function cacheNamespace(): string
    {
        return substr(
            hash('sha256', ($this->apiKey() ?? '').'|'.($this->baseUrl() ?? '')),
            0,
            12,
        );
    }
}
