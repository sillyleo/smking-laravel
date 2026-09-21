<?php

declare(strict_types=1);

namespace Smking\Laravel\Delivery;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
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
    private const MAX_RESPONSE_BYTES = 10_485_760;

    private readonly Closure $clock;

    private readonly DeliveryCapacity $capacity;

    private readonly DeliveryLocalStore $local;

    private readonly DeliveryTargetState $targets;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly HttpFactory $http,
        private readonly ConfigRepository $config,
        ?Closure $clock = null,
        private readonly int $cacheFormat = DeliverySnapshot::CACHE_FORMAT,
        private readonly ?DeliveryWorklist $worklist = null,
        private readonly ?DeliveryReportOutbox $reports = null,
        ?DeliveryTargetState $targets = null,
        ?DeliveryCapacity $capacity = null,
        private readonly ?DeliveryReconciliation $reconciliation = null,
        ?DeliveryLocalStore $local = null,
    ) {
        $this->clock = $clock ?? static fn (): int => (int) floor(microtime(true) * 1000);
        $this->capacity = $capacity ?? new DeliveryCapacity($cache, $config);
        $this->local = $local ?? new DeliveryLocalStore((string) $config->get('smking.delivery.local_store_path', storage_path('app/smking-delivery')));
        $this->targets = $targets ?? new DeliveryTargetState($cache, $config, $this->clock, $this->local);
    }

    public function read(string $resource, string $identifier, WaitBudget $budget): DeliveryResult
    {
        $cached = $this->peek($resource, $identifier);
        if ($cached->snapshot !== null) {
            return $cached;
        }
        if (in_array($cached->error, [
            'access_denied',
            'configuration',
            'disabled',
            'invalid_identifier',
            'target_pending',
            'withdrawn',
        ], true)) {
            $this->scheduleBackground($resource, $identifier, $cached);

            return $cached;
        }
        if ($cached->error === 'cache_unavailable') {
            $this->reports?->recordFailure('cache_unavailable');

            return $cached;
        }
        if (in_array($resource, ['aeo', 'markdown'], true)) {
            $this->scheduleBackground($resource, $identifier, $cached);

            return $cached;
        }

        $result = $this->refresh($resource, $identifier, $budget);
        $this->scheduleBackground($resource, $identifier, $result);

        return $result;
    }

    /** Local-only read used by preflight and guarded legacy rollback. */
    public function peek(string $resource, string $identifier): DeliveryResult
    {
        if (! $this->configured()) {
            return new DeliveryResult(error: 'configuration');
        }
        if (DeliveryIdentifier::parameters($resource, $identifier) === null) {
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
            $target = $this->targetResult($this->targetRecord($resource, $identifier), $resource, $identifier);

            return $target ?? $this->cached($resource, $identifier);
        } catch (Throwable) {
            return new DeliveryResult(error: 'cache_unavailable');
        }
    }

    public function hasState(string $resource, string $identifier): bool
    {
        if (DeliveryIdentifier::parameters($resource, $identifier) === null) {
            return false;
        }
        try {
            return $this->state($resource, $identifier) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Bounded CLI upgrade of an explicitly known v2 identifier, without HTTP.
     * Import fences before content. Existing durable records always win; this
     * is not a restore command and never imports PHP legacy SDK objects.
     */
    public function importCached(string $resource, string $identifier): DeliveryResult
    {
        if ($this->config->get('smking.delivery.mode', 'legacy') !== 'legacy'
            || ! $this->configured()
            || DeliveryIdentifier::parameters($resource, $identifier) === null
        ) {
            return new DeliveryResult(error: 'configuration');
        }
        try {
            $credential = $this->validateCredential($this->cache->get($this->credentialKey()));
            if (! $this->importRecord($this->credentialKey(), $credential)) {
                return new DeliveryResult(error: 'cache_busy');
            }
            foreach (['cms-page', 'aeo', 'markdown', 'site-file'] as $kind) {
                $control = $this->validateControl($this->cache->get($this->controlKey($kind)));
                if (! $this->importRecord($this->controlKey($kind), $control)) {
                    return new DeliveryResult(error: 'cache_busy');
                }
            }
            if ($resource === 'cms-page') {
                // Retain withdrawal fences even when notifications are not yet enabled.
                if (! $this->targets->importCached($identifier)) {
                    return new DeliveryResult(error: 'cache_busy');
                }
            }
            $imported = $this->withLock($this->stateKey($resource, $identifier), function () use ($resource, $identifier): bool {
                if ($this->state($resource, $identifier) !== null) {
                    return true;
                }
                $state = $this->validateState($resource, $identifier, $this->cache->get($this->stateKey($resource, $identifier)));
                if ($state === null) {
                    return true;
                }
                if ($state['success'] !== null && DeliverySnapshot::fromCache(
                    $resource, $identifier, $state['success'], ($this->clock)(), $this->cacheFormat,
                ) === null) {
                    throw new RuntimeException('delivery_import_invalid');
                }
                if ($state['missing'] !== null) {
                    // Expired missing entries still clear an older success; do
                    // not import an unvalidated payload as a withdrawal signal.
                    $at = $state['missing']['payload']['delivery']['validated_at'] ?? null;
                    $time = is_string($at) ? strtotime($at) : false;
                    if ($time === false || DeliverySnapshot::fromCache(
                        $resource, $identifier, $state['missing'], $time * 1000, $this->cacheFormat,
                    ) === null) {
                        throw new RuntimeException('delivery_import_invalid');
                    }
                }

                return $this->putState($resource, $identifier, $state);
            });
            if ($imported !== true) return new DeliveryResult(error: 'cache_busy');
            $result = $this->peek($resource, $identifier);
            if ($result->httpStatus === 200 && $result->snapshot !== null && $this->reconciliation !== null
                && ! $this->reconciliation->remember($result->snapshot, imported: true)
            ) return new DeliveryResult(error: 'registry_unavailable');
            return $result;
        } catch (Throwable) {
            return new DeliveryResult(error: 'cache_unavailable');
        }
    }

    private function importRecord(string $key, ?array $record): bool
    {
        if ($record === null) {
            return true;
        }
        return $this->withLock($key, fn (): bool => $this->local->read($key) !== null || $this->put($key, $record)) === true;
    }

    public function refresh(string $resource, string $identifier, WaitBudget $budget): DeliveryResult
    {
        $result = $this->performRefresh($resource, $identifier, $budget);
        $this->reports?->recordFailure($result->error);

        return $result;
    }

    private function performRefresh(string $resource, string $identifier, WaitBudget $budget): DeliveryResult
    {
        if (! $this->configured()) {
            return new DeliveryResult(error: 'configuration');
        }
        if (DeliveryIdentifier::parameters($resource, $identifier) === null) {
            return new DeliveryResult(error: 'invalid_identifier');
        }
        if (! $this->resourceEnabled($resource)) {
            return new DeliveryResult(error: 'disabled');
        }
        if (! $this->capacity->available()) {
            return new DeliveryResult(error: 'capacity');
        }

        try {
            $authority = $this->authority($resource);
            if ($authority !== null) {
                return $authority;
            }
            $target = $this->targetRecord($resource, $identifier);
            if (($target['target']['action'] ?? null) === 'withdraw') {
                return new DeliveryResult(httpStatus: 404, error: 'withdrawn');
            }
            $targetToken = $target['token'] ?? null;

            $control = $this->control($resource);
            $now = ($this->clock)();
            if (($control['circuit_until'] ?? 0) > $now) {
                return new DeliveryResult(error: 'backoff');
            }

            $result = $this->capacity->run($resource, $identifier, function () use ($resource, $identifier, $budget, $target, $targetToken): DeliveryResult {
                $cached = $this->cached($resource, $identifier);
                if ($cached->snapshot?->isFresh(($this->clock)())
                    && $this->matchesTarget($cached->snapshot, $target)
                    && ! ($this->state($resource, $identifier)['refresh_requested'] ?? false)
                ) {
                    if ($cached->httpStatus === 200 && $this->reconciliation !== null
                        && ! $this->reconciliation->remember($cached->snapshot)
                    ) {
                        return new DeliveryResult(error: 'registry_unavailable');
                    }
                    return $cached;
                }

                $generation = bin2hex(random_bytes(16));
                $prepared = $this->guardTarget($resource, $identifier, $targetToken, function () use ($resource, $identifier, $generation): mixed {
                    return $this->withLock($this->stateKey($resource, $identifier), function () use ($resource, $identifier, $generation): bool {
                        $state = $this->state($resource, $identifier) ?? $this->emptyState($resource, $identifier);
                        $state['generation'] = $generation;

                        return $this->putState($resource, $identifier, $state);
                    });
                });
                if ($prepared !== true) {
                    return new DeliveryResult(error: $prepared === false ? 'superseded' : 'cache_busy');
                }

                $fetched = $budget->run(fn (float $seconds): DeliveryResult => $this->request($resource, $identifier, $seconds));
                if (! $fetched instanceof DeliveryResult) {
                    return new DeliveryResult(error: 'budget_exhausted');
                }
                if ($fetched->snapshot === null) {
                    $this->recordControlFailure($resource, $fetched);

                    return $fetched;
                }
                $publication = $fetched->snapshot->payload['delivery']['publication'] ?? null;
                if ($publication !== null) {
                    // Adopt only source-paired ordering evidence, never just a
                    // different hash or a newer validation timestamp. A webhook
                    // received during this GET wins, even if the GET looks newer.
                    $accepted = $this->targets->applyFetched($publication, $targetToken);
                    if (! in_array($accepted['status'], ['applied', 'duplicate'], true)) {
                        return new DeliveryResult(error: match ($accepted['status']) {
                            'superseded' => 'superseded',
                            'unavailable' => 'cache_unavailable',
                            default => 'target_mismatch',
                        });
                    }
                    $target = $accepted['record'];
                    $targetToken = $target['token'];
                }
                if (! $this->matchesTarget($fetched->snapshot, $target)) {
                    $mismatch = new DeliveryResult(httpStatus: $fetched->httpStatus, error: 'target_mismatch');
                    $this->recordControlFailure($resource, $mismatch);

                    return $mismatch;
                }

                return $this->commit($resource, $identifier, $generation, $targetToken, $fetched);
            });

            return $result instanceof DeliveryResult
                ? $result
                : new DeliveryResult(error: 'capacity');
        } catch (Throwable) {
            return new DeliveryResult(error: 'cache_unavailable');
        }
    }

    /** Register an unversioned update hint without deleting last-known-good content. */
    public function requestRefresh(string $resource, string $identifier): bool
    {
        if (! $this->configured()
            || DeliveryIdentifier::parameters($resource, $identifier) === null
            || ! $this->resourceEnabled($resource)
        ) {
            return false;
        }
        try {
            $requested = $this->withLock($this->stateKey($resource, $identifier), function () use ($resource, $identifier): bool {
                $state = $this->state($resource, $identifier) ?? $this->emptyState($resource, $identifier);
                // Fence downloads started before this hint, even while the old
                // body is fresh. The worker clears the hint only on commit.
                $state['generation'] = bin2hex(random_bytes(16));
                $state['refresh_requested'] = true;

                return $this->putState($resource, $identifier, $state);
            });

            return $requested === true && ($this->worklist?->schedule($resource, $identifier) ?? false);
        } catch (Throwable) {
            return false;
        }
    }

    /** Prevent an in-flight response from repopulating an invalidated key. */
    public function invalidate(string $resource, string $identifier): bool
    {
        if (! $this->configured()
            || DeliveryIdentifier::parameters($resource, $identifier) === null
            || ! $this->capacity->available()
        ) {
            return false;
        }

        try {
            $invalidated = $this->withLock($this->stateKey($resource, $identifier), function () use ($resource, $identifier): bool {
                return $this->putState($resource, $identifier, $this->emptyState($resource, $identifier));
            }) === true;
            if ($invalidated) {
                $this->reconciliation?->forget($resource, $identifier);
            }

            return $invalidated;
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

    /** @return array{format:int,token:string,target:array<string,mixed>,updated_at:int}|null */
    private function targetRecord(string $resource, string $identifier): ?array
    {
        return $this->targets->read($identifier, $resource);
    }

    public function publication(string $resource, string $identifier): ?DeliveryResult
    {
        try {
            $record = $this->targetRecord($resource, $identifier);
            if (($record['target']['action'] ?? null) === 'withdraw') {
                return new DeliveryResult(httpStatus: 404, error: 'withdrawn');
            }
            $authority = $this->authority($resource);
            if ($authority !== null) {
                return $authority;
            }

            return $this->targetResult($record, $resource, $identifier);
        } catch (Throwable) {
            return new DeliveryResult(error: 'cache_unavailable');
        }
    }

    /** @param array{target:array<string,mixed>}|null $record */
    private function targetResult(?array $record, string $resource, string $identifier): ?DeliveryResult
    {
        if ($record === null) {
            return null;
        }
        if ($record['target']['action'] === 'withdraw') {
            return new DeliveryResult(httpStatus: 404, error: 'withdrawn');
        }
        $cached = $this->cached($resource, $identifier);
        if ($cached->snapshot !== null) {
            if ($this->matchesTarget($cached->snapshot, $record)) {
                return $cached;
            }

            // A newer publication may reveal a missed withdrawal. Old bodies
            // must not reappear if persisting the replacement then fails.
            $bodyRevision = $cached->snapshot->payload['delivery']['publication']['revision'] ?? 0;
            if ($record['target']['withdrawalRevision'] > 0
                && $bodyRevision <= $record['target']['withdrawalRevision']
            ) {
                return new DeliveryResult(error: 'target_pending', refreshRequired: true);
            }

            return new DeliveryResult(
                snapshot: $cached->snapshot,
                httpStatus: $cached->httpStatus,
                error: 'target_pending',
                refreshRequired: true,
            );
        }

        return new DeliveryResult(error: 'target_pending', refreshRequired: true);
    }

    /** @param array{target:array<string,mixed>}|null $target */
    private function matchesTarget(DeliverySnapshot $snapshot, ?array $target): bool
    {
        return $target === null
            || ($target['target']['action'] === 'update'
                && ($snapshot->payload['delivery']['content_version'] ?? null) === $target['target']['contentVersion'])
            || ($target['target']['action'] === 'withdraw'
                && ($snapshot->payload['status'] ?? null) === 'not_found'
                && DeliveryTargetState::normalize($snapshot->payload['delivery']['publication'] ?? []) === $target['target']);
    }

    private function guardTarget(
        string $resource,
        string $identifier,
        ?string $targetToken,
        callable $operation,
    ): mixed {
        return $this->targets->guard($identifier, $targetToken, $operation, $resource);
    }

    private function commit(
        string $resource,
        string $identifier,
        string $generation,
        ?string $targetToken,
        DeliveryResult $result,
    ): DeliveryResult {
        $committed = $this->guardTarget($resource, $identifier, $targetToken, function () use ($resource, $identifier, $generation, $result): mixed {
            return $this->withLock($this->stateKey($resource, $identifier), function () use ($resource, $identifier, $generation, $result): string {
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
                if (! $missing && $this->reconciliation !== null
                    && ! $this->reconciliation->remember($result->snapshot)
                ) {
                    return 'registry_unavailable';
                }
                $state['success'] = $missing ? null : $result->snapshot->toCache($this->cacheFormat);
                $state['missing'] = $missing ? $result->snapshot->toCache($this->cacheFormat) : null;
                unset($state['refresh_requested']);
                if (! $this->putState($resource, $identifier, $state)) {
                    return 'cache_unavailable';
                }
                $this->writeControl($resource, denied: false, status: null, circuitUntil: 0);

                return 'committed';
            });
        });

        if ($committed === 'committed') {
            if ($result->httpStatus === 404) {
                $this->reconciliation?->forget($resource, $identifier);
            }

            return $result;
        }

        return new DeliveryResult(error: $committed === false
                ? 'superseded'
                : (is_string($committed) ? $committed : 'cache_busy'));
    }

    private function request(string $resource, string $identifier, float $remainingSeconds): DeliveryResult
    {
        if (! is_finite($remainingSeconds) || $remainingSeconds < 0.001) {
            return new DeliveryResult(error: 'budget_exhausted');
        }

        $parameters = DeliveryIdentifier::parameters($resource, $identifier);
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

    private function recordControlFailure(string $resource, DeliveryResult $result): void
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
        $credential = $this->validateCredential($this->local->read($this->credentialKey()));
        if (($credential['denied'] ?? false) === true) {
            return new DeliveryResult(httpStatus: (int) $credential['status'], error: 'access_denied');
        }
        $control = $this->control($resource);
        if (($control['denied'] ?? false) === true) {
            return new DeliveryResult(httpStatus: (int) $control['status'], error: 'access_denied');
        }

        return null;
    }

    private function validateCredential(mixed $credential): ?array
    {
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
        }
        return $credential;
    }

    /**
     * @return array{format: int, denied: bool, status: ?int, circuit_until: int}|null
     */
    private function control(string $resource): ?array
    {
        return $this->validateControl($this->local->read($this->controlKey($resource)));
    }

    private function validateControl(mixed $control): ?array
    {
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
        $written = $this->withLock($this->controlKey($resource), function () use ($resource, $denied, $status, $circuitUntil): bool {
            $current = $this->control($resource);
            if (! $denied && ($current['denied'] ?? false) === true) {
                return true;
            }

            return $this->put($this->controlKey($resource), [
                'format' => 1,
                'denied' => $denied,
                'status' => $status,
                'circuit_until' => $circuitUntil,
            ]);
        });
        if ($written !== true) {
            throw new RuntimeException('delivery_control_write_failed');
        }
    }

    /**
     * @return array{format: int, resource: string, identifier: string, generation: string, success: mixed, missing: mixed}|null
     */
    private function state(string $resource, string $identifier): ?array
    {
        return $this->validateState($resource, $identifier, $this->local->read($this->stateKey($resource, $identifier)));
    }

    private function validateState(string $resource, string $identifier, mixed $state): ?array
    {
        if ($state === null) {
            return null;
        }
        if (! is_array($state)
            || count($state) !== (array_key_exists('refresh_requested', $state) ? 7 : 6)
            || (array_key_exists('refresh_requested', $state) && $state['refresh_requested'] !== true)
            || ($state['format'] ?? null) !== 1
            || ($state['resource'] ?? null) !== $resource
            || ($state['identifier'] ?? null) !== $identifier
            || ! is_string($state['generation'] ?? null)
            || ! array_key_exists('success', $state)
            || ! array_key_exists('missing', $state)
            || ($state['success'] !== null && (! is_array($state['success']) || ($state['success']['payload']['status'] ?? null) !== 'ready'))
            || ($state['missing'] !== null && (! is_array($state['missing']) || ($state['missing']['payload']['status'] ?? null) !== 'not_found'))
            || ($state['success'] !== null && $state['missing'] !== null)
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

    private function withLock(string $key, callable $operation): mixed
    {
        return $this->local->locked($key, $operation);
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

    private function scheduleBackground(string $resource, string $identifier, DeliveryResult $result): void
    {
        if ($resource === 'aeo'
            && $result->error === 'cache_miss'
            && str_starts_with($identifier, 'path:')
        ) {
            $this->reports?->observePath(substr($identifier, 5));
        }
        if ($result->refreshRequired
            || in_array($result->error, [
                'backoff',
                'budget_exhausted',
                'cache_busy',
                'cache_miss',
                'cache_unavailable',
                'capacity',
                'invalid_response',
                'superseded',
                'target_mismatch',
                'target_pending',
                'transport',
                'upstream',
            ], true)
        ) {
            $this->worklist?->schedule($resource, $identifier);
        }
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
        return $this->local->write($key, $value);
    }

    private function putState(string $resource, string $identifier, array $state): bool
    {
        return $this->put($this->stateKey($resource, $identifier), $state);
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
