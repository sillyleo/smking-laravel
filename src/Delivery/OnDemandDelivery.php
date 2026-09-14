<?php

declare(strict_types=1);

namespace Smking\Laravel\Delivery;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Versioned customer-side content delivery behind one read interface.
 *
 * Reads never wait for locks. Fresh or usable stale content wins; only a cold
 * CMS/site-file read may perform one bounded GET. Explicit refreshes use the
 * same cross-process capacity and preserve the last successful body on error.
 */
final class OnDemandDelivery
{
    private const RETENTION_SECONDS = 604_800;

    private const MAX_RESPONSE_BYTES = 10_485_760;

    private readonly Closure $clock;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly HttpFactory $http,
        private readonly ConfigRepository $config,
        ?Closure $clock = null,
        private readonly int $cacheFormat = DeliverySnapshot::CACHE_FORMAT,
    ) {
        $this->clock = $clock ?? static fn (): int => (int) floor(microtime(true) * 1000);
    }

    public function read(string $resource, string $identifier, WaitBudget $budget): DeliveryResult
    {
        if (! $this->configured()) {
            return new DeliveryResult(error: 'configuration');
        }
        if (self::parameters($resource, $identifier) === null) {
            return new DeliveryResult(error: 'invalid_identifier');
        }
        if (! $this->resourceEnabled($resource)) {
            return new DeliveryResult(error: 'disabled');
        }

        try {
            $authority = $this->authority($resource);
            if ($authority !== null) {
                return $authority;
            }

            $cached = $this->cached($resource, $identifier);
            if ($cached->snapshot !== null) {
                return $cached;
            }

            if (in_array($resource, ['aeo', 'markdown'], true)) {
                return new DeliveryResult(error: 'cache_miss', refreshRequired: true);
            }

            return $this->refresh($resource, $identifier, $budget);
        } catch (Throwable) {
            return new DeliveryResult(error: 'cache_unavailable');
        }
    }

    public function refresh(string $resource, string $identifier, WaitBudget $budget): DeliveryResult
    {
        if (! $this->configured()) {
            return new DeliveryResult(error: 'configuration');
        }
        if (self::parameters($resource, $identifier) === null) {
            return new DeliveryResult(error: 'invalid_identifier');
        }
        if (! $this->resourceEnabled($resource)) {
            return new DeliveryResult(error: 'disabled');
        }
        if (! $this->supportsCrossProcessLocks()) {
            return new DeliveryResult(error: 'capacity');
        }

        try {
            $authority = $this->authority($resource);
            if ($authority !== null) {
                return $authority;
            }

            $control = $this->control($resource);
            $now = ($this->clock)();
            if (($control['circuit_until'] ?? 0) > $now) {
                return new DeliveryResult(error: 'backoff');
            }

            $result = $this->withCapacity($resource, $identifier, function () use ($resource, $identifier, $budget): DeliveryResult {
                $cached = $this->cached($resource, $identifier);
                if ($cached->snapshot?->isFresh(($this->clock)())) {
                    return $cached;
                }

                $generation = bin2hex(random_bytes(16));
                $prepared = $this->withLock($this->stateKey($resource, $identifier), function () use ($resource, $identifier, $generation): bool {
                    $state = $this->state($resource, $identifier) ?? $this->emptyState($resource, $identifier);
                    $state['generation'] = $generation;

                    return $this->put($this->stateKey($resource, $identifier), $state);
                });
                if ($prepared !== true) {
                    return new DeliveryResult(error: 'cache_busy');
                }

                $fetched = $budget->run(fn (float $seconds): DeliveryResult => $this->request($resource, $identifier, $seconds));
                if (! $fetched instanceof DeliveryResult) {
                    return new DeliveryResult(error: 'budget_exhausted');
                }
                if ($fetched->snapshot === null) {
                    $this->recordFailure($resource, $fetched);

                    return $fetched;
                }

                return $this->commit($resource, $identifier, $generation, $fetched);
            });

            return $result instanceof DeliveryResult
                ? $result
                : new DeliveryResult(error: 'capacity');
        } catch (Throwable) {
            return new DeliveryResult(error: 'cache_unavailable');
        }
    }

    /** Prevent an in-flight response from repopulating an invalidated key. */
    public function invalidate(string $resource, string $identifier): bool
    {
        if (! $this->configured()
            || self::parameters($resource, $identifier) === null
            || ! $this->supportsCrossProcessLocks()
        ) {
            return false;
        }

        try {
            return $this->withLock($this->stateKey($resource, $identifier), function () use ($resource, $identifier): bool {
                return $this->put($this->stateKey($resource, $identifier), $this->emptyState($resource, $identifier));
            }) === true;
        } catch (Throwable) {
            return false;
        }
    }

    private function cached(string $resource, string $identifier): DeliveryResult
    {
        $state = $this->state($resource, $identifier);
        if ($state === null) {
            return new DeliveryResult(error: 'cache_miss');
        }

        $now = ($this->clock)();
        foreach ([['missing', 404], ['success', 200]] as [$field, $status]) {
            $snapshot = DeliverySnapshot::fromCache(
                $resource,
                $identifier,
                $state[$field] ?? null,
                $now,
                $this->cacheFormat,
            );
            if ($snapshot !== null) {
                return new DeliveryResult(
                    snapshot: $snapshot,
                    httpStatus: $status,
                    refreshRequired: ! $snapshot->isFresh($now),
                );
            }
        }

        return new DeliveryResult(error: 'cache_miss');
    }

    private function commit(
        string $resource,
        string $identifier,
        string $generation,
        DeliveryResult $result,
    ): DeliveryResult {
        $committed = $this->withLock($this->stateKey($resource, $identifier), function () use ($resource, $identifier, $generation, $result): string {
            $state = $this->state($resource, $identifier);
            if ($state === null || $state['generation'] !== $generation) {
                return 'superseded';
            }
            if (! $result->snapshot->isUsable(($this->clock)())) {
                return 'expired';
            }

            foreach (['success', 'missing'] as $field) {
                $existing = DeliverySnapshot::fromCache(
                    $resource,
                    $identifier,
                    $state[$field] ?? null,
                    ($this->clock)(),
                    $this->cacheFormat,
                );
                if ($existing !== null && $existing->validatedAtMs > $result->snapshot->validatedAtMs) {
                    return 'out_of_order';
                }
            }

            $missing = $result->httpStatus === 404;
            $state['success'] = $missing ? null : $result->snapshot->toCache($this->cacheFormat);
            $state['missing'] = $missing ? $result->snapshot->toCache($this->cacheFormat) : null;
            if (! $this->put($this->stateKey($resource, $identifier), $state)) {
                return 'cache_unavailable';
            }
            $this->writeControl($resource, denied: false, status: null, circuitUntil: 0);

            return 'committed';
        });

        return $committed === 'committed'
            ? $result
            : new DeliveryResult(error: is_string($committed) ? $committed : 'cache_busy');
    }

    private function request(string $resource, string $identifier, float $remainingSeconds): DeliveryResult
    {
        if (! is_finite($remainingSeconds) || $remainingSeconds < 0.001) {
            return new DeliveryResult(error: 'budget_exhausted');
        }

        $parameters = self::parameters($resource, $identifier);
        $baseUrl = $this->baseUrl();
        $apiKey = $this->apiKey();
        if ($parameters === null || ! $this->validConfiguration($baseUrl, $apiKey)) {
            return new DeliveryResult(error: 'configuration');
        }

        $connectTimeout = $this->positiveFloat('smking.delivery.connect_timeout', 0.5, 10.0);
        if ($connectTimeout === null) {
            return new DeliveryResult(error: 'configuration');
        }

        try {
            $response = $this->http->withOptions([
                'timeout' => min(10.0, $remainingSeconds),
                'connect_timeout' => min($connectTimeout, $remainingSeconds),
                'read_timeout' => min(10.0, $remainingSeconds),
                'allow_redirects' => false,
                'http_errors' => false,
                'on_headers' => static function (ResponseInterface $response): void {
                    if ((int) $response->getHeaderLine('Content-Length') > self::MAX_RESPONSE_BYTES) {
                        throw new RuntimeException('delivery_response_too_large');
                    }
                },
                'progress' => static function ($total, $downloaded): void {
                    if ($downloaded > self::MAX_RESPONSE_BYTES) {
                        throw new RuntimeException('delivery_response_too_large');
                    }
                },
            ])->acceptJson()->get(
                $baseUrl.'/api/v2/public/'.$resource,
                ['key' => $apiKey] + $parameters,
            );
        } catch (Throwable) {
            return new DeliveryResult(error: 'transport');
        }

        if (! in_array($response->status(), [200, 404], true)) {
            return new DeliveryResult(
                httpStatus: $response->status(),
                error: in_array($response->status(), [401, 403], true) ? 'access_denied' : 'upstream',
                denialScope: $this->denialScope($response),
            );
        }
        if ($this->mediaType($response) !== 'application/json'
            || strlen($response->body()) > self::MAX_RESPONSE_BYTES
        ) {
            return new DeliveryResult(httpStatus: $response->status(), error: 'invalid_response');
        }

        $snapshot = DeliverySnapshot::fromResponse(
            $resource,
            $identifier,
            $response->status(),
            $response->json(),
            ($this->clock)(),
        );

        return $snapshot === null
            ? new DeliveryResult(httpStatus: $response->status(), error: 'invalid_response')
            : new DeliveryResult(snapshot: $snapshot, httpStatus: $response->status());
    }

    private function recordFailure(string $resource, DeliveryResult $result): void
    {
        if ($result->httpStatus === 401 || $result->denialScope === 'site') {
            if (! $this->put($this->credentialKey(), [
                'format' => 1,
                'denied' => true,
                'status' => $result->httpStatus,
            ])) {
                throw new RuntimeException('delivery_authority_write_failed');
            }

            return;
        }
        if ($result->httpStatus === 403) {
            $resources = match ($result->denialScope) {
                'aeo' => ['aeo', 'markdown', 'site-file'],
                'cms' => ['cms-page'],
                default => [$resource],
            };
            foreach ($resources as $deniedResource) {
                $this->writeControl($deniedResource, denied: true, status: 403, circuitUntil: 0);
            }

            return;
        }

        $seconds = $this->boundedInteger('smking.delivery.circuit_seconds', 30, 1, 300);
        $this->writeControl(
            $resource,
            denied: false,
            status: null,
            circuitUntil: ($this->clock)() + $seconds * 1000,
        );
    }

    private function authority(string $resource): ?DeliveryResult
    {
        $credential = $this->cache->get($this->credentialKey());
        if ($credential !== null) {
            if (! is_array($credential)
                || ($credential['format'] ?? null) !== 1
                || ! is_bool($credential['denied'] ?? null)
                || (! is_null($credential['status'] ?? null) && ! is_int($credential['status']))
                || ($credential['denied'] === true && ! in_array($credential['status'], [401, 403], true))
                || ($credential['denied'] === false && $credential['status'] !== null)
            ) {
                throw new RuntimeException('delivery_credential_invalid');
            }
            if ($credential['denied'] === true) {
                return new DeliveryResult(httpStatus: (int) $credential['status'], error: 'access_denied');
            }
        }

        $control = $this->control($resource);
        if (($control['denied'] ?? false) === true) {
            return new DeliveryResult(httpStatus: (int) $control['status'], error: 'access_denied');
        }

        return null;
    }

    /**
     * @return array{format: int, denied: bool, status: ?int, circuit_until: int}|null
     */
    private function control(string $resource): ?array
    {
        $control = $this->cache->get($this->controlKey($resource));
        if ($control === null) {
            return null;
        }
        if (! is_array($control)
            || ($control['format'] ?? null) !== 1
            || ! is_bool($control['denied'] ?? null)
            || (! is_null($control['status'] ?? null) && ! is_int($control['status']))
            || ! is_int($control['circuit_until'] ?? null)
            || ($control['denied'] === true && $control['status'] !== 403)
            || ($control['denied'] === false && $control['status'] !== null)
        ) {
            throw new RuntimeException('delivery_control_invalid');
        }

        return $control;
    }

    private function writeControl(string $resource, bool $denied, ?int $status, int $circuitUntil): void
    {
        if (! $this->put($this->controlKey($resource), [
            'format' => 1,
            'denied' => $denied,
            'status' => $status,
            'circuit_until' => $circuitUntil,
        ])) {
            throw new RuntimeException('delivery_control_write_failed');
        }
    }

    /**
     * @return array{format: int, resource: string, identifier: string, generation: string, success: mixed, missing: mixed}|null
     */
    private function state(string $resource, string $identifier): ?array
    {
        $state = $this->cache->get($this->stateKey($resource, $identifier));
        if ($state === null) {
            return null;
        }
        if (! is_array($state)
            || ($state['format'] ?? null) !== 1
            || ($state['resource'] ?? null) !== $resource
            || ($state['identifier'] ?? null) !== $identifier
            || ! is_string($state['generation'] ?? null)
            || ! array_key_exists('success', $state)
            || ! array_key_exists('missing', $state)
        ) {
            throw new RuntimeException('delivery_state_invalid');
        }

        return $state;
    }

    /**
     * @return array{format: int, resource: string, identifier: string, generation: string, success: null, missing: null}
     */
    private function emptyState(string $resource, string $identifier): array
    {
        return [
            'format' => 1,
            'resource' => $resource,
            'identifier' => $identifier,
            'generation' => bin2hex(random_bytes(16)),
            'success' => null,
            'missing' => null,
        ];
    }

    private function withCapacity(string $resource, string $identifier, callable $operation): mixed
    {
        $slots = $this->boundedInteger('smking.delivery.capacity', 1, 1, 8);
        $leaseSeconds = $this->boundedInteger('smking.delivery.lease_seconds', 15, 15, 60);
        $flight = $this->cache->lock($this->flightKey($resource, $identifier), $leaseSeconds);
        if (! $flight->get()) {
            return null;
        }

        try {
            for ($slot = 0; $slot < $slots; $slot++) {
                $lease = $this->cache->lock($this->capacityKey().':slot:'.$slot, $leaseSeconds);
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

    private function withLock(string $key, callable $operation): mixed
    {
        $lock = $this->cache->lock($key.':mutex', 5);
        if (! $lock->get()) {
            return null;
        }
        try {
            return $operation();
        } finally {
            $lock->release();
        }
    }

    private function supportsCrossProcessLocks(): bool
    {
        if (! method_exists($this->cache, 'getStore')) {
            return false;
        }

        $store = $this->cache->getStore();

        return $store instanceof LockProvider
            && ($store instanceof FileStore || $store instanceof RedisStore);
    }

    private function resourceEnabled(string $resource): bool
    {
        return ! in_array($resource, ['aeo', 'markdown', 'site-file'], true)
            || $this->config->get('smking.delivery.aeo_enabled', true) === true;
    }

    private function configured(): bool
    {
        return $this->config->get('smking.cache.enabled', true) === true
            && $this->cacheFormat >= 1
            && $this->cacheFormat <= 100;
    }

    /**
     * @return array<string, string|int>|null
     */
    private static function parameters(string $resource, string $identifier): ?array
    {
        if ($resource === 'cms-page' && str_starts_with($identifier, 'slug:')) {
            $slug = substr($identifier, 5);
            if (strlen($slug) > 200
                || str_starts_with($slug, '/')
                || str_ends_with($slug, '/')
                || preg_match('/[?#\\\\\x00-\x1f\x7f]/', $slug)
                || in_array('.', explode('/', $slug), true)
                || in_array('..', explode('/', $slug), true)
            ) {
                return null;
            }

            return ['slug' => $slug];
        }

        if ($resource === 'site-file' && in_array($identifier, ['kind:sitemap', 'kind:robots', 'kind:llms_txt'], true)) {
            return ['kind' => substr($identifier, 5)];
        }

        if (in_array($resource, ['aeo', 'markdown'], true) && str_starts_with($identifier, 'path:/')) {
            $path = substr($identifier, 5);
            if (strlen($path) > 500
                || str_starts_with($path, '//')
                || preg_match('/[?#\\\\\x00-\x1f\x7f]/', $path)
            ) {
                return null;
            }

            return ['path' => $path];
        }

        if (in_array($resource, ['aeo', 'markdown'], true)
            && preg_match('/^product_id:([1-9][0-9]*)$/D', $identifier, $matches) === 1
            && (float) $matches[1] <= 2_147_483_647
        ) {
            return ['product_id' => (int) $matches[1]];
        }

        return null;
    }

    private function validConfiguration(string $baseUrl, string $apiKey): bool
    {
        if (preg_match('/^pk_[A-Za-z0-9_-]+$/D', $apiKey) !== 1 || strlen($apiKey) > 256) {
            return false;
        }
        if (preg_match('/[\x00-\x20\x7f]/', $baseUrl)) {
            return false;
        }

        $parts = parse_url($baseUrl);
        if (! is_array($parts)
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            return false;
        }
        if (($parts['scheme'] ?? null) === 'https') {
            return true;
        }

        return ($parts['scheme'] ?? null) === 'http'
            && in_array($parts['host'], ['127.0.0.1', 'localhost', '[::1]'], true)
            && in_array($this->config->get('app.env'), ['local', 'testing'], true);
    }

    private function mediaType(Response $response): string
    {
        return strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
    }

    private function denialScope(Response $response): ?string
    {
        if ($response->status() !== 403
            || strlen($response->body()) > 8192
            || $this->mediaType($response) !== 'application/json'
        ) {
            return null;
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            return null;
        }
        $scope = is_array($payload['denial'] ?? null) ? ($payload['denial']['scope'] ?? null) : null;
        if (($payload['status'] ?? null) !== 'unavailable'
            || ! is_array($payload['denial'] ?? null)
            || ($payload['denial']['contract'] ?? null) !== '2'
            || ! in_array($scope, ['site', 'aeo', 'cms'], true)
            || ($payload['error'] ?? null) !== $scope.'_disabled'
        ) {
            return null;
        }

        return $scope;
    }

    private function boundedInteger(string $key, int $default, int $minimum, int $maximum): int
    {
        $value = $this->config->get($key, $default);
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException('delivery_configuration_invalid');
        }

        return $value;
    }

    private function positiveFloat(string $key, float $default, float $maximum): ?float
    {
        $value = $this->config->get($key, $default);
        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) && $number > 0 ? min($number, $maximum) : null;
    }

    private function put(string $key, array $value): bool
    {
        return $this->cache->put($key, $value, self::RETENTION_SECONDS) === true;
    }

    private function rootKey(): string
    {
        return 'smking:delivery:v2:c'.$this->cacheFormat.':'.$this->site();
    }

    private function stateKey(string $resource, string $identifier): string
    {
        return $this->rootKey().':state:'.hash('sha256', $resource.'|'.$identifier);
    }

    private function controlKey(string $resource): string
    {
        return 'smking:delivery:v2:control:'.$this->site().':'.$resource;
    }

    private function credentialKey(): string
    {
        return 'smking:delivery:v2:credential:'.$this->site();
    }

    private function capacityKey(): string
    {
        return 'smking:delivery:capacity';
    }

    private function flightKey(string $resource, string $identifier): string
    {
        return $this->capacityKey().':flight:'.$this->site().':'.hash('sha256', $resource.'|'.$identifier);
    }

    private function site(): string
    {
        return substr(hash('sha256', $this->apiKey().'|'.$this->baseUrl()), 0, 24);
    }

    private function apiKey(): string
    {
        $value = $this->config->get('smking.api_key');

        return is_string($value) ? $value : '';
    }

    private function baseUrl(): string
    {
        $value = $this->config->get('smking.base_url');

        return is_string($value) ? rtrim($value, '/') : '';
    }
}
