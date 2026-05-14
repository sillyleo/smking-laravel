<?php

declare(strict_types=1);

namespace Smking\Laravel\Http\Controllers;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Smking\Laravel\CmsClient;

/**
 * Receives SmKing publish webhooks at `/api/smking/webhook` (auto-mounted
 * by SmkingServiceProvider). HMAC-verifies the request against the
 * customer's configured `smking.webhook_secret`, then evicts the
 * corresponding CmsClient cache entry so the next page render reads
 * fresh content from SaaS instead of waiting for the 5min TTL.
 *
 * Wire contract — matches @smking-saas/features/cms/lib/webhook.ts
 * (signWebhook):
 *
 *   POST /api/smking/webhook
 *   X-Smking-Signature: sha256=<hex>
 *   X-Smking-Event: cms.page.published
 *
 *   { "event": "cms.page.published",
 *     "siteId": "uuid",
 *     "slug": "hello",
 *     "publishedAt": "2026-05-14T10:00:00Z",
 *     "deliveredAt": "2026-05-14T10:00:01.234Z" }
 *
 * Returns 200 on accept / cache-eviction, 401 on bad sig, 400 on
 * malformed payload. Always responds quickly (no upstream calls in
 * handler) — SaaS treats >2xx as delivery failure and logs warning,
 * but does NOT retry (acceptable for cache invalidation: next TTL
 * cycle catches up).
 */
class WebhookController
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly CacheFactory $cache,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $secret = $this->config->get('smking.webhook_secret');
        if (! is_string($secret) || $secret === '') {
            // Misconfigured customer site — webhook arrives but no
            // secret to verify against. 503 (vs 401) because the bug
            // is on receiver side, not signer.
            $this->logger?->warning(
                'smking: webhook received but SMKING_WEBHOOK_SECRET not configured',
            );

            return response()->json(['error' => 'webhook_secret_missing'], 503);
        }

        // Raw body needed for signature verification — re-serialising
        // through JSON parse would change byte order / spacing and
        // invalidate the HMAC. Laravel's $request->getContent()
        // returns the raw body untouched.
        $rawBody = $request->getContent();
        $providedSig = $request->header('X-Smking-Signature');

        if (! $this->verifySignature($rawBody, $providedSig, $secret)) {
            $this->logger?->warning('smking: webhook signature verification failed');

            return response()->json(['error' => 'invalid_signature'], 401);
        }

        // Parse payload AFTER sig check — we trust the bytes only when
        // signature matches.
        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            return response()->json(['error' => 'invalid_payload'], 400);
        }

        $event = $payload['event'] ?? null;
        $slug = $payload['slug'] ?? null;

        if ($event !== 'cms.page.published' || ! is_string($slug) || $slug === '') {
            // Unknown event types or missing slug — accept (200) so SaaS
            // doesn't retry, but do nothing. Forward-compat: future
            // event types (cms.page.unpublished / cms.page.deleted) can
            // add branches without breaking older customer SDKs.
            return response()->json(['ok' => true, 'note' => 'no_action_taken']);
        }

        // Evict the CmsClient cache for this slug. CmsClient uses
        // cacheNamespace + slug as the cache key; reconstruct that here.
        $this->evictCmsCache($slug);

        $this->logger?->info('smking: CMS cache evicted via webhook', [
            'slug' => $slug,
            'event' => $event,
        ]);

        return response()->json(['ok' => true, 'evicted' => $slug]);
    }

    /**
     * Constant-time HMAC-SHA256 verification. `hash_equals` runs in
     * constant time over equal-length strings so timing attacks can't
     * extract the secret from response latency.
     */
    private function verifySignature(
        string $rawBody,
        ?string $providedHeader,
        string $secret,
    ): bool {
        if (! is_string($providedHeader) || ! str_starts_with($providedHeader, 'sha256=')) {
            return false;
        }
        $provided = substr($providedHeader, strlen('sha256='));
        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $provided);
    }

    /**
     * Cache key shape MUST match CmsClient::remember(). Reconstructing
     * it here (vs binding CmsClient via DI and calling forSlug) keeps
     * the controller pure — no upstream call risk inside webhook
     * handler, just a local cache forget.
     */
    private function evictCmsCache(string $slug): void
    {
        $cacheConfig = $this->config->get('smking.cache', []);
        $store = $cacheConfig['store'] ?? null;
        $repository = $store ? $this->cache->store($store) : $this->cache->store();

        $apiKey = (string) ($this->config->get('smking.api_key') ?? '');
        $baseUrl = (string) ($this->config->get('smking.base_url') ?? '');
        $namespace = substr(
            hash('sha256', $apiKey.'|'.rtrim($baseUrl, '/')),
            0,
            12,
        );

        $cacheKey = ($cacheConfig['cms_prefix'] ?? 'smking:cms:').$namespace.':'.$slug;
        $repository->forget($cacheKey);
    }
}
