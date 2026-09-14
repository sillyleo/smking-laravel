<?php

declare(strict_types=1);

namespace Smking\Laravel\Delivery;

use Illuminate\Cache\FileStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use RuntimeException;

/** Shared non-blocking origin capacity for on-demand and guarded rollback reads. */
final class DeliveryCapacity
{
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
    ) {
    }

    public function available(): bool
    {
        if (! method_exists($this->cache, 'getStore')) {
            return false;
        }
        $store = $this->cache->getStore();

        return $store instanceof LockProvider
            && ($store instanceof FileStore || $store instanceof RedisStore);
    }

    public function run(string $resource, string $identifier, callable $operation): mixed
    {
        if (! $this->available() || DeliveryIdentifier::parameters($resource, $identifier) === null) {
            return null;
        }

        $leaseSeconds = $this->integer('lease_seconds', 15, 15, 60);
        $flight = $this->cache->lock($this->flightKey($resource, $identifier), $leaseSeconds);
        if (! $flight->get()) {
            return null;
        }

        try {
            $slots = $this->integer('capacity', 1, 1, 8);
            for ($slot = 0; $slot < $slots; $slot++) {
                $lease = $this->cache->lock('smking:delivery:capacity:slot:'.$slot, $leaseSeconds);
                if (! $lease->get()) {
                    continue;
                }
                try {
                    return $operation();
                } finally {
                    $lease->release();
                }
            }

            return null;
        } finally {
            $flight->release();
        }
    }

    private function integer(string $name, int $default, int $minimum, int $maximum): int
    {
        $value = $this->config->get('smking.delivery.'.$name, $default);
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException('delivery_configuration_invalid');
        }

        return $value;
    }

    private function flightKey(string $resource, string $identifier): string
    {
        $site = substr(hash('sha256',
            (string) $this->config->get('smking.api_key').'|'
            .rtrim((string) $this->config->get('smking.base_url'), '/')
        ), 0, 24);

        return 'smking:delivery:capacity:flight:'.$site.':'.hash('sha256', $resource.'|'.$identifier);
    }
}
