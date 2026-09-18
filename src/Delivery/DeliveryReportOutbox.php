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

/** Bounded local capture; only an independent CLI tick can send it. */
final class DeliveryReportOutbox
{
    private const FORMAT = 1;

    private const RETENTION_SECONDS = 604_800;

    private const EVENT_LIFETIME_MS = 600_000;

    private readonly Closure $clock;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
        ?Closure $clock = null,
        private readonly int $eventCapacity = 100,
        private readonly int $observationCapacity = 20,
        private readonly int $heartbeatSeconds = 180,
    ) {
        $this->clock = $clock ?? static fn (): int => (int) floor(microtime(true) * 1000);
    }

    public function prepare(): bool
    {
        if (! $this->supported()) {
            return false;
        }
        try {
            return $this->locked(function (): bool {
                $record = $this->load();
                $record['heartbeat'] = ($this->clock)();
                $this->save($record);

                return true;
            }) === true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array{bot:?string,bot_category:?string,purpose:string} $classification */
    public function capture(array $classification, string $path, int $status): bool
    {
        $now = ($this->clock)();
        $event = [
            'event_id' => DeliveryReportPayload::uuid(),
            'occurred_at' => DeliveryReportPayload::timestamp($now),
            'bot' => $classification['bot'] ?? null,
            'bot_category' => $classification['bot_category'] ?? null,
            'purpose' => $classification['purpose'] ?? null,
            'path' => $path,
            'status' => $status,
        ];
        if (! DeliveryReportPayload::validHit($event)) {
            return false;
        }

        return $this->captureChange(function (array &$record) use ($event): bool {
            if (count($record['events']) >= $this->eventCapacity) {
                $this->lose($record, 'overflow');

                return false;
            }
            $record['events'][$event['event_id']] = $event;

            return true;
        });
    }

    public function observePath(string $path): bool
    {
        if (! DeliveryReportPayload::safePath($path)) {
            return false;
        }

        return $this->captureChange(function (array &$record, int $now) use ($path): bool {
            $key = hash('sha256', $path);
            $previous = $record['observations'][$key] ?? null;
            if (is_array($previous) && $this->timestampMs($previous['observed_at']) > $now - 60_000) {
                return true;
            }
            if ($previous === null && count($record['observations']) >= $this->observationCapacity) {
                $this->lose($record, 'observation_overflow');

                return false;
            }
            $record['observations'][$key] = [
                'observation_id' => DeliveryReportPayload::uuid(),
                'path' => $path,
                'observed_at' => DeliveryReportPayload::timestamp($now),
            ];

            return true;
        });
    }

    public function recordFailure(?string $error): bool
    {
        $kind = match ($error) {
            'transport' => 'transport',
            'upstream', 'invalid_response', 'access_denied' => 'upstream',
            'capacity', 'budget_exhausted', 'cache_unavailable', 'cache_busy' => 'capacity',
            default => null,
        };
        if ($kind === null) {
            return false;
        }

        return $this->captureChange(function (array &$record, int $now) use ($kind): bool {
            $record['failures'][$kind] = min(1_000_000, $record['failures'][$kind] + 1);
            $record['failures_at'] = $now;

            return true;
        });
    }

    /** @return array{sent:int,accepted:int,error:?string} */
    public function runOnce(DeliveryReportTransport $transport, bool $prepareOnly = false): array
    {
        $summary = ['sent' => 0, 'accepted' => 0, 'error' => null];
        if (PHP_SAPI !== 'cli' || ! $this->supported() || $transport->metadata() === null) {
            $summary['error'] = 'configuration_or_storage';

            return $summary;
        }
        if ($prepareOnly) {
            if (! $this->prepare()) {
                $summary['error'] = 'configuration_or_storage';
            }

            return $summary;
        }

        $sender = $this->cache->lock('smking:delivery:report-sender', 15);
        $senderAcquired = false;
        try {
            if (! $sender->get()) {
                $summary['error'] = 'sender_busy';

                return $summary;
            }
            $senderAcquired = true;
            $prepared = $this->locked(function () use ($transport): array {
                $record = $this->load();
                $now = ($this->clock)();
                $this->expire($record, $now);
                $record['heartbeat'] = $now;
                if ($record['next_at'] > $now) {
                    $this->save($record);

                    return ['pending' => null, 'error' => null];
                }
                if ($record['pending'] !== null && $record['pending']['attempts'] >= 3) {
                    $this->discardPending($record, $record['pending']);
                    $this->lose($record, 'retry_exhausted');
                    $record['pending'] = null;
                    $record['next_at'] = $now + 300_000;
                    $this->save($record);

                    return ['pending' => null, 'error' => 'retry_exhausted'];
                }
                if ($record['pending'] === null) {
                    $events = array_slice($record['events'], 0, 20, true);
                    $observations = array_slice($record['observations'], 0, 20 - count($events), true);
                    $dueHeartbeat = $record['last_sent_at'] <= $now - $this->heartbeatSeconds * 1000;
                    if ($events === [] && $observations === [] && array_sum($record['failures']) === 0 && ! $dueHeartbeat) {
                        $this->save($record);

                        return ['pending' => null, 'error' => null];
                    }
                    $reportId = DeliveryReportPayload::uuid();
                    $body = $transport->body($reportId, $now, $events, $observations, $record['failures']);
                    if ($body === null) {
                        throw new RuntimeException('delivery_report_body_invalid');
                    }
                    $record['pending'] = [
                        'id' => $reportId,
                        'body' => $body,
                        'event_ids' => array_keys($events),
                        'observations' => $observations,
                        'failures' => $record['failures'],
                        'created_at' => $now,
                        'attempts' => 0,
                    ];
                }
                $record['pending']['attempts']++;
                $record['next_at'] = $now + 60_000;
                $this->save($record);

                return ['pending' => $record['pending'], 'error' => null];
            });
            if (! is_array($prepared)
                || ! array_key_exists('pending', $prepared)
                || ! array_key_exists('error', $prepared)
            ) {
                $summary['error'] = 'outbox_busy';

                return $summary;
            }
            if ($prepared['error'] !== null) {
                $summary['error'] = $prepared['error'];

                return $summary;
            }
            $pending = $prepared['pending'];
            if ($pending === null) {
                return $summary;
            }

            $summary['sent'] = 1;
            $result = $transport->send($pending['body']);
            $saved = $this->locked(function () use ($pending, $result): bool {
                $record = $this->load();
                if (($record['pending']['id'] ?? null) !== $pending['id']) {
                    return false;
                }
                $record['last_error'] = $result['error'];
                $record['next_at'] = ($this->clock)() + $result['retry_after'] * 1000;
                if ($result['accepted']) {
                    $this->discardPending($record, $pending);
                    $record['pending'] = null;
                    $record['last_sent_at'] = ($this->clock)();
                }
                $this->save($record);

                return true;
            });
            if ($saved !== true) {
                throw new RuntimeException('delivery_report_ack_unavailable');
            }
            $summary['accepted'] = $result['accepted'] ? 1 : 0;
            $summary['error'] = $result['error'];
        } catch (Throwable) {
            $summary['error'] = 'outbox_unavailable';
        } finally {
            if ($senderAcquired) {
                try {
                    $sender->release();
                } catch (Throwable) {
                    // No synchronous fallback.
                }
            }
        }

        return $summary;
    }

    /** Read-only health; paths and event details are deliberately omitted. */
    public function status(): array
    {
        try {
            if (! $this->supported()) {
                throw new RuntimeException('unsupported');
            }
            $record = $this->load();
            $now = ($this->clock)();

            return [
                'heartbeat_recent' => $record['heartbeat'] <= $now + 30_000
                    && $record['heartbeat'] > $now - $this->heartbeatSeconds * 1000,
                'pending_events' => count($record['events']),
                'pending_observations' => count($record['observations']),
                'pending_failures' => $record['failures'],
                'losses' => $record['losses'],
                'last_error' => $record['last_error'],
                'error' => null,
            ];
        } catch (Throwable) {
            return [
                'heartbeat_recent' => false,
                'pending_events' => null,
                'pending_observations' => null,
                'pending_failures' => [],
                'losses' => [],
                'last_error' => null,
                'error' => 'outbox_unavailable',
            ];
        }
    }

    private function captureChange(callable $change): bool
    {
        if (! $this->supported()) {
            return false;
        }
        try {
            return $this->locked(function () use ($change): bool {
                $record = $this->load();
                $now = ($this->clock)();
                $this->expire($record, $now);
                if ($record['heartbeat'] > $now + 30_000
                    || $record['heartbeat'] <= $now - $this->heartbeatSeconds * 1000
                ) {
                    if (($record['losses']['background_unavailable'] ?? 0) === 0) {
                        $this->lose($record, 'background_unavailable');
                        $this->save($record);
                    }

                    return false;
                }
                $changed = $change($record, $now);
                $this->save($record);

                return $changed;
            }) === true;
        } catch (Throwable) {
            return false;
        }
    }

    private function discardPending(array &$record, array $pending): void
    {
        foreach ($pending['event_ids'] as $id) {
            unset($record['events'][$id]);
        }
        foreach ($pending['observations'] as $key => $observation) {
            if (($record['observations'][$key]['observation_id'] ?? null) === $observation['observation_id']) {
                unset($record['observations'][$key]);
            }
        }
        foreach ($pending['failures'] as $kind => $count) {
            $record['failures'][$kind] = max(0, $record['failures'][$kind] - $count);
        }
    }

    private function expire(array &$record, int $now): void
    {
        foreach ($record['events'] as $id => $event) {
            $time = $this->timestampMs($event['occurred_at']);
            if ($time <= $now - self::EVENT_LIFETIME_MS || $time > $now + 30_000) {
                unset($record['events'][$id]);
                $this->lose($record, 'expired');
            }
        }
        foreach ($record['observations'] as $key => $observation) {
            $time = $this->timestampMs($observation['observed_at']);
            if ($time <= $now - self::EVENT_LIFETIME_MS || $time > $now + 30_000) {
                unset($record['observations'][$key]);
                $this->lose($record, 'expired');
            }
        }
        if ($record['failures_at'] > 0 && $record['failures_at'] <= $now - self::EVENT_LIFETIME_MS) {
            $record['failures'] = ['transport' => 0, 'upstream' => 0, 'capacity' => 0];
            $this->lose($record, 'expired');
        }
        if ($record['pending'] !== null
            && ($record['pending']['created_at'] <= $now - self::EVENT_LIFETIME_MS
                || $record['pending']['created_at'] > $now + 30_000)
        ) {
            $this->discardPending($record, $record['pending']);
            $record['pending'] = null;
            $this->lose($record, 'expired');
        }
    }

    private function timestampMs(string $timestamp): int
    {
        return (int) floor((float) (new \DateTimeImmutable($timestamp))->format('U.u') * 1000);
    }

    private function lose(array &$record, string $reason): void
    {
        $record['losses'][$reason] = min(1_000_000, ($record['losses'][$reason] ?? 0) + 1);
    }

    private function load(): array
    {
        $record = $this->cache->get($this->key());
        if ($record === null) {
            return [
                'format' => self::FORMAT,
                'heartbeat' => 0,
                'events' => [],
                'observations' => [],
                'failures' => ['transport' => 0, 'upstream' => 0, 'capacity' => 0],
                'failures_at' => 0,
                'pending' => null,
                'next_at' => 0,
                'last_sent_at' => 0,
                'last_error' => null,
                'losses' => [],
            ];
        }
        if (! is_array($record)
            || ($record['format'] ?? null) !== self::FORMAT
            || ! is_int($record['heartbeat'] ?? null)
            || ! is_array($record['events'] ?? null)
            || count($record['events']) > $this->eventCapacity
            || ! is_array($record['observations'] ?? null)
            || count($record['observations']) > $this->observationCapacity
            || ! is_array($record['failures'] ?? null)
            || array_keys($record['failures']) !== ['transport', 'upstream', 'capacity']
            || ! is_int($record['failures_at'] ?? null)
            || ! array_key_exists('pending', $record)
            || ! is_int($record['next_at'] ?? null)
            || ! is_int($record['last_sent_at'] ?? null)
            || ! array_key_exists('last_error', $record)
            || ($record['last_error'] !== null && ! is_string($record['last_error']))
            || ! is_array($record['losses'] ?? null)
        ) {
            throw new RuntimeException('delivery_report_outbox_invalid');
        }
        foreach ($record['events'] as $id => $event) {
            if ($id !== ($event['event_id'] ?? null) || ! DeliveryReportPayload::validHit($event)) {
                throw new RuntimeException('delivery_report_event_invalid');
            }
        }
        foreach ($record['observations'] as $key => $observation) {
            if ($key !== hash('sha256', $observation['path'] ?? '') || ! DeliveryReportPayload::validObservation($observation)) {
                throw new RuntimeException('delivery_report_observation_invalid');
            }
        }
        foreach (array_merge($record['failures'], $record['losses']) as $count) {
            if (! is_int($count) || $count < 0 || $count > 1_000_000) {
                throw new RuntimeException('delivery_report_counter_invalid');
            }
        }
        if ($record['pending'] !== null && ! $this->validPending($record['pending'])) {
            throw new RuntimeException('delivery_report_pending_invalid');
        }

        return $record;
    }

    private function validPending(mixed $pending): bool
    {
        if (! is_array($pending)
            || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $pending['id'] ?? '') !== 1
        ) {
            return false;
        }
        if (! is_string($pending['body'] ?? null)
            || strlen($pending['body']) > 32_768
            || ! is_array($pending['event_ids'] ?? null)
            || ! array_is_list($pending['event_ids'])
            || ! is_array($pending['observations'] ?? null)
            || ! is_array($pending['failures'] ?? null)
            || array_keys($pending['failures']) !== ['transport', 'upstream', 'capacity']
            || ! is_int($pending['created_at'] ?? null)
            || ! is_int($pending['attempts'] ?? null)
            || $pending['attempts'] < 0
            || $pending['attempts'] > 3
        ) {
            return false;
        }
        try {
            $body = json_decode($pending['body'], true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return false;
        }
        foreach ($pending['failures'] as $count) {
            if (! is_int($count) || $count < 0 || $count > 1_000_000) {
                return false;
            }
        }
        $bodyEvents = is_array($body) ? $this->loadEventBodies($body['crawler_hits'] ?? []) : [];
        foreach ($pending['observations'] as $key => $observation) {
            if ($key !== hash('sha256', $observation['path'] ?? '')
                || ! DeliveryReportPayload::validObservation($observation)
            ) {
                return false;
            }
        }
        if (! is_array($body)
            || ($body['report_id'] ?? null) !== $pending['id']
            || ($body['failures'] ?? null) !== $pending['failures']
            || array_keys($bodyEvents) !== $pending['event_ids']
            || ($body['crawler_hits'] ?? null) !== array_values($bodyEvents)
            || ($body['path_observations'] ?? null) !== array_values($pending['observations'])
        ) {
            return false;
        }

        return true;
    }

    /** @return array<string, array<string, mixed>> */
    private function loadEventBodies(mixed $events): array
    {
        if (! is_array($events) || ! array_is_list($events)) {
            return [];
        }
        $result = [];
        foreach ($events as $event) {
            if (! DeliveryReportPayload::validHit($event) || isset($result[$event['event_id']])) {
                return [];
            }
            $result[$event['event_id']] = $event;
        }

        return $result;
    }

    private function save(array $record): void
    {
        if ($this->cache->put($this->key(), $record, self::RETENTION_SECONDS) !== true) {
            throw new RuntimeException('delivery_report_outbox_write_failed');
        }
    }

    private function key(): string
    {
        return 'smking:delivery:v2:reports:'.substr(hash('sha256',
            (string) $this->config->get('smking.api_key').'|'
            .(string) $this->config->get('smking.base_url').'|'
            .(string) $this->config->get('app.url')
        ), 0, 24);
    }

    private function supported(): bool
    {
        if ($this->config->get('smking.cache.enabled', true) !== true
            || $this->eventCapacity < 1
            || $this->eventCapacity > 100
            || $this->observationCapacity < 1
            || $this->observationCapacity > 20
            || $this->heartbeatSeconds < 30
            || $this->heartbeatSeconds > 900
            || ! method_exists($this->cache, 'getStore')
        ) {
            return false;
        }
        $store = $this->cache->getStore();

        return $store instanceof LockProvider
            && ($store instanceof FileStore || $store instanceof RedisStore);
    }

    private function locked(callable $operation): mixed
    {
        $lock = $this->cache->lock($this->key().':mutex', 5);
        if (! $lock->get()) {
            return null;
        }
        try {
            return $operation();
        } finally {
            $lock->release();
        }
    }
}
