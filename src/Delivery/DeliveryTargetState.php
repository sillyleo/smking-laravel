<?php

declare(strict_types=1);

namespace Smking\Laravel\Delivery;

use Closure;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use RuntimeException;
use Throwable;

/** Ordered publish/withdraw fences shared by on-demand and rollback reads. */
final class DeliveryTargetState
{
    private const FORMAT = 1;

    private readonly Closure $clock;

    private readonly DeliveryLocalStore $local;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
        ?Closure $clock = null,
        ?DeliveryLocalStore $local = null,
    ) {
        $this->clock = $clock ?? static fn (): int => (int) floor(microtime(true) * 1000);
        $this->local = $local ?? new DeliveryLocalStore((string) $config->get('smking.delivery.local_store_path', storage_path('app/smking-delivery')));
    }

    public function enabled(): bool
    {
        return $this->config->get('smking.delivery.notifications_enabled', false) === true;
    }

    public function available(): bool
    {
        $scope = $this->config->get('smking.delivery.notifications_scope');
        if (! $this->enabled()
            || $this->config->get('smking.cache.enabled', true) !== true
            || ! is_string($scope)
            || preg_match('/^[a-f0-9]{64}$/D', $scope) !== 1
            || ! method_exists($this->cache, 'getStore')
        ) {
            return false;
        }
        $store = $this->cache->getStore();

        return $store instanceof LockProvider
            && ($store instanceof FileStore || $store instanceof RedisStore);
    }

    /** @return array{format:int,token:string,target:array<string,mixed>,updated_at:int}|null */
    public function read(string $identifier, string $resource = 'cms-page'): ?array
    {
        // The receiving toggle must not erase a previously accepted fence.
        if (DeliveryIdentifier::parameters($resource, $identifier) === null) {
            throw new RuntimeException('delivery_target_unavailable');
        }

        $record = $this->local->read($this->key($identifier, $resource));
        return $this->validateRecord($identifier, $record, $resource);
    }

    /** Explicit upgrade only: retain an existing durable fence, never overwrite it. */
    public function importCached(string $identifier): bool
    {
        if (DeliveryIdentifier::parameters('cms-page', $identifier) === null) {
            return false;
        }
        return $this->locked($identifier, function () use ($identifier): bool {
            if ($this->local->read($this->key($identifier)) !== null) {
                return true;
            }
            $record = $this->validateRecord($identifier, $this->cache->get($this->key($identifier)));
            return $record === null || $this->local->write($this->key($identifier), $record);
        }) === true;
    }

    private function validateRecord(string $identifier, mixed $record, string $resource = 'cms-page'): ?array
    {
        if ($record === null) {
            return null;
        }
        if (! is_array($record)
            || count($record) !== 4
            || ($record['format'] ?? null) !== self::FORMAT
            || preg_match('/^[a-f0-9]{32}$/D', $record['token'] ?? '') !== 1
            || ! is_array($record['target'] ?? null)
            || self::normalize($record['target']) !== $record['target']
            || ($record['target']['identifier'] ?? null) !== $identifier
            || ($record['target']['resource'] ?? null) !== $resource
            || ! is_int($record['updated_at'] ?? null)
            || $record['updated_at'] < 0
        ) {
            throw new RuntimeException('delivery_target_invalid');
        }

        return $record;
    }

    /** @return array{status:string,record:?array} */
    public function apply(array $target): array
    {
        return $this->applyOrdered($target);
    }

    /** Validated GET evidence uses the same fences, even without webhook configuration. */
    public function applyFetched(array $target, ?string $expectedToken): array
    {
        return $this->applyOrdered($target, true, $expectedToken);
    }

    private function applyOrdered(array $target, bool $fetched = false, ?string $expectedToken = null): array
    {
        $target = self::normalize($target);
        if ($target === null || (! $fetched && ! $this->available())) {
            return ['status' => 'invalid', 'record' => null];
        }

        try {
            $result = $this->locked($target['identifier'], function () use ($target, $fetched, $expectedToken): array {
                $current = $this->read($target['identifier'], $target['resource']);
                if ($fetched && ($current['token'] ?? null) !== $expectedToken) {
                    return ['status' => 'superseded', 'record' => $current];
                }
                if ($current !== null) {
                    $previous = $current['target'];
                    $order = [$target['revision'], $target['generation']]
                        <=> [$previous['revision'], $previous['generation']];
                    if ($order === 0) {
                        return [
                            'status' => $target === $previous ? 'duplicate' : 'conflict',
                            'record' => $current,
                        ];
                    }
                    if ($order < 0) {
                        return [
                            'status' => $target['withdrawalRevision'] <= $previous['withdrawalRevision']
                                ? 'obsolete'
                                : 'conflict',
                            'record' => $current,
                        ];
                    }
                    if ($target['withdrawalRevision'] < $previous['withdrawalRevision']) {
                        return ['status' => 'conflict', 'record' => $current];
                    }
                }

                $record = [
                    'format' => self::FORMAT,
                    'token' => bin2hex(random_bytes(16)),
                    'target' => $target,
                    'updated_at' => ($this->clock)(),
                ];
                if ($this->local->write($this->key($target['identifier'], $target['resource']), $record) !== true) {
                    throw new RuntimeException('delivery_target_write_failed');
                }

                return ['status' => 'applied', 'record' => $record];
            }, $target['resource']);

            return is_array($result)
                ? $result
                : ['status' => 'unavailable', 'record' => null];
        } catch (Throwable) {
            return ['status' => 'unavailable', 'record' => null];
        }
    }

    /**
     * Run a short local state mutation only while the expected target is current.
     *
     * @return mixed False means superseded; null means the target lock was busy.
     */
    public function guard(string $identifier, ?string $expectedToken, callable $operation, string $resource = 'cms-page'): mixed
    {
        if (DeliveryIdentifier::parameters($resource, $identifier) === null) {
            throw new RuntimeException('delivery_target_unavailable');
        }

        return $this->locked($identifier, function () use ($identifier, $expectedToken, $operation, $resource): mixed {
            if (($this->read($identifier, $resource)['token'] ?? null) !== $expectedToken) {
                return false;
            }

            return $operation();
        }, $resource);
    }

    /** @return array<string, mixed>|null */
    public static function normalize(array $target): ?array
    {
        if (count($target) !== 7
            || ! is_string($target['resource'] ?? null)
            || ! is_string($target['identifier'] ?? null)
            || DeliveryIdentifier::parameters($target['resource'], $target['identifier']) === null
            || ! in_array($target['action'] ?? null, ['update', 'withdraw'], true)
            || ! array_key_exists('contentVersion', $target)
        ) {
            return null;
        }
        foreach (['revision', 'generation', 'withdrawalRevision'] as $field) {
            if (! is_int($target[$field] ?? null)
                || $target[$field] < 0
                || $target[$field] > 2_147_483_647
            ) {
                return null;
            }
        }
        if ($target['withdrawalRevision'] > $target['revision']) {
            return null;
        }
        if ($target['action'] === 'update') {
            if ($target['generation'] < 1
                || ! is_string($target['contentVersion'])
                || preg_match('/^sha256:[a-f0-9]{64}$/D', $target['contentVersion']) !== 1
            ) {
                return null;
            }
        } elseif ($target['revision'] < 1
            || $target['withdrawalRevision'] !== $target['revision']
            || $target['contentVersion'] !== null
        ) {
            return null;
        }

        return [
            'resource' => $target['resource'],
            'identifier' => $target['identifier'],
            'action' => $target['action'],
            'revision' => $target['revision'],
            'generation' => $target['generation'],
            'withdrawalRevision' => $target['withdrawalRevision'],
            'contentVersion' => $target['contentVersion'],
        ];
    }

    private function key(string $identifier, string $resource = 'cms-page'): string
    {
        // Keep existing CMS keys intact; AEO and Markdown may share identifiers.
        $identity = $resource === 'cms-page' ? $identifier : $resource.'|'.$identifier;
        return 'smking:delivery:v2:target:'.$this->site().':'.hash('sha256', $identity);
    }

    private function site(): string
    {
        return substr(hash('sha256',
            (string) $this->config->get('smking.api_key').'|'
            .rtrim((string) $this->config->get('smking.base_url'), '/')
        ), 0, 24);
    }

    private function locked(string $identifier, callable $operation, string $resource = 'cms-page'): mixed
    {
        return $this->local->locked($this->key($identifier, $resource), $operation);
    }
}
