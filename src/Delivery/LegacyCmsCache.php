<?php

declare(strict_types=1);

namespace Smking\Laravel\Delivery;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use RuntimeException;
use Smking\Laravel\Data\CmsPage;
use Throwable;

/** Owns unchanged legacy CMS keys and fences in-flight invalidation races. */
final class LegacyCmsCache
{
    public function __construct(
        private readonly CacheFactory $cache,
        private readonly ConfigRepository $config,
        private readonly ?DeliveryCapacity $capacity = null,
    ) {
    }

    /** Return only an already cached legacy page; never call the resolver. */
    public function peek(string $slug): ?CmsPage
    {
        $options = $this->options();
        $ttl = (int) ($options['cms_ttl'] ?? 300);
        if (($options['enabled'] ?? true) !== true || $ttl <= 0) {
            return null;
        }

        try {
            $cached = $this->store()->get($this->key($slug, $options));

            return $cached instanceof CmsPage ? $cached : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function remember(string $slug, callable $resolver): CmsPage
    {
        $options = $this->options();
        $ttl = (int) ($options['cms_ttl'] ?? 300);
        if (($options['enabled'] ?? true) !== true || $ttl <= 0) {
            return $resolver();
        }

        try {
            $store = $this->store();
            $key = $this->key($slug, $options);
            $cached = $store->get($key);
            if ($cached instanceof CmsPage) {
                return $cached;
            }

            $guarded = $this->guarded($store);
            $generation = $guarded
                ? $this->locked($store, $key, fn (): string => $this->generation($store, $key))
                : null;
            $page = $this->config->get('smking.delivery.notifications_enabled', false) === true
                ? $this->capacity?->run('cms-page', 'slug:'.$slug, $resolver)
                : $resolver();
            if (! $page instanceof CmsPage) {
                return CmsPage::serverError();
            }
            $writeTtl = match ($page->status) {
                CmsPage::STATUS_READY => $ttl,
                CmsPage::STATUS_NOT_FOUND => min($ttl, (int) ($options['cms_not_found_ttl'] ?? 15)),
                CmsPage::STATUS_SERVER_ERROR => min($ttl, (int) ($options['cms_server_error_ttl'] ?? 15)),
                default => $ttl,
            };
            $save = function () use ($store, $key, $generation, $page, $writeTtl, $guarded): CmsPage {
                if ($guarded && $store->get($this->guardKey($key).':generation') !== $generation) {
                    return CmsPage::serverError();
                }
                if ($writeTtl > 0 && $store->put($key, $page, $writeTtl) !== true) {
                    return CmsPage::serverError();
                }

                return $page;
            };

            return $guarded ? $this->locked($store, $key, $save) : $save();
        } catch (Throwable) {
            return CmsPage::serverError();
        }
    }

    public function invalidate(string $slug): bool
    {
        if (DeliveryIdentifier::parameters('cms-page', 'slug:'.$slug) === null) {
            return false;
        }

        try {
            $store = $this->store();
            $key = $this->key($slug, $this->options());
            $remove = function () use ($store, $key): bool {
                if ($this->guarded($store)) {
                    $this->rotate($store, $key);
                }
                $store->forget($key);

                return $store->get($key) === null;
            };

            return $this->guarded($store)
                ? $this->locked($store, $key, $remove) === true
                : $remove();
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        $options = $this->config->get('smking.cache', []);

        return is_array($options) ? $options : [];
    }

    private function store(): CacheRepository
    {
        $name = $this->config->get('smking.cache.store');

        return is_string($name) && $name !== '' ? $this->cache->store($name) : $this->cache->store();
    }

    /** @param array<string, mixed> $options */
    private function key(string $slug, array $options): string
    {
        $namespace = substr(hash('sha256',
            (string) $this->config->get('smking.api_key').'|'
            .rtrim((string) $this->config->get('smking.base_url'), '/')
        ), 0, 12);

        return ($options['cms_prefix'] ?? 'smking:cms:').$namespace.':'.$slug;
    }

    private function guarded(CacheRepository $store): bool
    {
        if (! method_exists($store, 'getStore')) {
            return false;
        }
        $backend = $store->getStore();

        return $backend instanceof LockProvider
            && ($backend instanceof ArrayStore || $backend instanceof FileStore || $backend instanceof RedisStore);
    }

    private function generation(CacheRepository $store, string $key): string
    {
        $generation = $store->get($this->guardKey($key).':generation');
        if ($generation === null) {
            return $this->rotate($store, $key);
        }
        if (! is_string($generation) || preg_match('/^[a-f0-9]{32}$/D', $generation) !== 1) {
            throw new RuntimeException('legacy_cms_generation_invalid');
        }

        return $generation;
    }

    private function rotate(CacheRepository $store, string $key): string
    {
        $generation = bin2hex(random_bytes(16));
        if ($store->put($this->guardKey($key).':generation', $generation, 604_800) !== true) {
            throw new RuntimeException('legacy_cms_generation_unavailable');
        }

        return $generation;
    }

    private function guardKey(string $key): string
    {
        return 'smking:cms-guard:v1:'.hash('sha256', $key);
    }

    private function locked(CacheRepository $store, string $key, callable $operation): mixed
    {
        $lock = $store->lock($this->guardKey($key).':mutation', 5);
        if (! $lock->get()) {
            throw new RuntimeException('legacy_cms_cache_busy');
        }
        try {
            return $operation();
        } finally {
            $lock->release();
        }
    }
}
