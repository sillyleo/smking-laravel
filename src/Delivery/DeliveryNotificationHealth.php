<?php

declare(strict_types=1);

namespace Smking\Laravel\Delivery;

use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Throwable;

/** A short-lived, per-resource receipt; never a permanent webhook guarantee. */
final class DeliveryNotificationHealth
{
    public const RESOURCES = ['cms-page', 'aeo', 'markdown', 'site-file'];

    private readonly Closure $clock;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => (int) floor(microtime(true) * 1000);
    }

    /** Called only after the authenticated, scoped receiver processes targets. */
    public function record(string $resource, bool $accepted): bool
    {
        if (! in_array($resource, self::RESOURCES, true)) {
            return false;
        }
        try {
            return $this->cache->put($this->key($resource), [
                'received_at' => ($this->clock)(), 'accepted' => $accepted,
            ], 604800) === true;
        } catch (Throwable) {
            return false;
        }
    }

    /** Missing, changed, expired or failed evidence always restores fallback. */
    public function status(string $resource): array
    {
        $base = ['available' => false, 'last_received_at' => null];
        $scope = $this->config->get('smking.delivery.notifications_scope');
        if (! in_array($resource, self::RESOURCES, true)
            || $this->config->get('smking.delivery.notifications_enabled', false) !== true
            || $this->config->get('smking.cache.enabled', true) !== true
            || ! is_string($this->config->get('smking.webhook_secret'))
            || $this->config->get('smking.webhook_secret') === ''
            || ! is_string($scope) || preg_match('/^[a-f0-9]{64}$/D', $scope) !== 1
        ) {
            return $base + ['reason' => 'disabled_or_unconfigured'];
        }
        try {
            $record = $this->cache->get($this->key($resource));
            if (! is_array($record) || count($record) !== 2
                || ! is_int($record['received_at'] ?? null) || $record['received_at'] < 0
                || ! is_bool($record['accepted'] ?? null)
            ) {
                return $base + ['reason' => 'unconfirmed'];
            }
            $age = ($this->clock)() - $record['received_at'];
            $available = $record['accepted'] && $age >= 0 && $age < 86400000;
            return ['available' => $available, 'last_received_at' => $record['received_at'],
                'reason' => ! $record['accepted'] ? 'processing_failed' : ($available ? 'recent_receipt' : 'expired')];
        } catch (Throwable) {
            return $base + ['reason' => 'storage_unavailable'];
        }
    }

    private function key(string $resource): string
    {
        $binding = [rtrim((string) $this->config->get('smking.base_url'), '/'),
            $this->config->get('smking.api_key'), $this->config->get('smking.webhook_secret'),
            $this->config->get('smking.delivery.notifications_scope')];
        return 'smking:delivery:v2:notification-health:'.hash('sha256', json_encode($binding)).':'.$resource;
    }
}
