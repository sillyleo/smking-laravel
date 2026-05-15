<?php

declare(strict_types=1);

namespace Smking\Laravel\Http\Controllers;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;

/**
 * Unified SmKing webhook receiver (v0.8.0+) — `/api/smking/webhook`
 * auto-mounted by SmkingServiceProvider.
 *
 * Substrate-pivot: single endpoint dispatched by `payload.kind`
 * (`"aeo" | "cms_page" | ...`). HMAC-SHA256 verified against
 * `smking.webhook_secret`; on match, evicts the corresponding cache
 * entries so the next page render reads fresh content from SaaS.
 *
 * Wire contract — matches the SaaS unified emitter
 * (@smking-saas/features/cms/lib/webhook.ts):
 *
 *   POST /api/smking/webhook
 *   X-Smking-Signature: sha256=<hex>
 *
 *   { "kind": "aeo" | "cms_page",
 *     "paths"?: ["/products/foo"],     ← AEO uses paths
 *     "slugs"?: ["hello"],             ← CMS uses slugs
 *     "deliveredAt": "2026-05-15T10:00:00Z" }
 *
 * Returns 200 on accept / eviction, 401 on bad sig, 400 on
 * malformed payload, 503 on missing webhook_secret. SaaS treats
 * non-2xx as delivery failure (logged, not retried — next TTL
 * cycle catches up).
 *
 * AEO behaviour: Laravel SDK currently has no `smking:aeo:*` cache
 * key namespace (AEO Laravel uses TTL + circuit breaker, not push-
 * based invalidation). For `kind=aeo` payloads we acknowledge (200)
 * and log — operator may use `php artisan smking:cache:purge` for
 * explicit AEO eviction. Push-based AEO invalidation can land later
 * without touching this endpoint's contract.
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
            $this->logger?->warning(
                'smking: webhook received but SMKING_WEBHOOK_SECRET not configured',
            );

            return response()->json(['error' => 'webhook_secret_missing'], 503);
        }

        $rawBody = $request->getContent();
        $providedSig = $request->header('X-Smking-Signature');

        if (! $this->verifySignature($rawBody, $providedSig, $secret)) {
            $this->logger?->warning('smking: webhook signature verification failed');

            return response()->json(['error' => 'invalid_signature'], 401);
        }

        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            return response()->json(['error' => 'invalid_payload'], 400);
        }

        $kind = $payload['kind'] ?? null;
        if (! is_string($kind) || $kind === '') {
            // No kind → no dispatch target. Acknowledge so SaaS doesn't
            // retry but record telemetry.
            return response()->json(['ok' => true, 'note' => 'no_action_taken']);
        }

        $evicted = 0;
        $paths = isset($payload['paths']) && is_array($payload['paths'])
            ? array_values(array_filter($payload['paths'], 'is_string'))
            : [];
        $slugs = isset($payload['slugs']) && is_array($payload['slugs'])
            ? array_values(array_filter($payload['slugs'], 'is_string'))
            : [];

        switch ($kind) {
            case 'cms_page':
                foreach ($slugs as $slug) {
                    $this->evictCmsCache($slug);
                    $evicted++;
                }
                $this->logger?->info('smking: CMS cache evicted via webhook', [
                    'slugs' => $slugs,
                ]);
                break;

            case 'aeo':
                // AEO Laravel SDK uses TTL + circuit breaker; no push-
                // invalidate cache namespace today. Ack + log for
                // observability; operator can `smking:cache:purge` for
                // explicit eviction.
                $this->logger?->info(
                    'smking: AEO webhook received (no Laravel push-invalidate cache today)',
                    ['paths' => $paths],
                );
                break;

            default:
                // Forward-compat: future kinds (widget_config, ...) can
                // add branches without breaking older SDKs. 200-ack
                // prevents SaaS retry storms during rollout.
                $this->logger?->info('smking: webhook ack for unknown kind', [
                    'kind' => $kind,
                ]);
                break;
        }

        return response()->json([
            'ok' => true,
            'kind' => $kind,
            'evicted' => $evicted,
        ]);
    }

    /**
     * Constant-time HMAC-SHA256 verification.
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
