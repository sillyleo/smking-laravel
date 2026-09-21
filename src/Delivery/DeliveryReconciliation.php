<?php

declare(strict_types=1);

namespace Smking\Laravel\Delivery;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use RuntimeException;
use Throwable;

/** Bounded local registry for one daily refresh of known successful content. */
final class DeliveryReconciliation
{
    private const FORMAT = 2;

    private readonly Closure $clock;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
        ?Closure $clock = null,
        private readonly int $maxItems = 500,
    ) {
        $this->clock = $clock ?? static fn (): int => (int) floor(microtime(true) * 1000);
    }

    public function scheduledMinute(): int
    {
        return (int) (hexdec(substr(hash('sha256', $this->site()), 0, 8)) % 60);
    }

    public function remember(DeliverySnapshot $snapshot): bool
    {
        if (($snapshot->payload['status'] ?? null) !== 'ready'
            || DeliveryIdentifier::parameters($snapshot->resource, $snapshot->identifier) === null
            || ! $this->supported()
        ) {
            return false;
        }

        try {
            $key = $this->itemKey($snapshot->resource, $snapshot->identifier);
            if (isset($this->load()['items'][$key])) {
                return true;
            }

            return $this->locked(function () use ($snapshot): bool {
                $record = $this->load();
                $key = $this->itemKey($snapshot->resource, $snapshot->identifier);
                if (isset($record['items'][$key])) {
                    return true;
                }
                if (! isset($record['items'][$key]) && count($record['items']) >= $this->maxItems) {
                    if ($record['overflowed_at'] === null) {
                        $record['overflowed_at'] = ($this->clock)();
                        $this->save($record);
                    }

                    return false;
                }
                $record['items'][$key] = [
                    'resource' => $snapshot->resource,
                    'identifier' => $snapshot->identifier,
                    'last_checked_on' => $this->localDate($snapshot->validatedAtMs),
                    'last_success_at' => $snapshot->validatedAtMs,
                    'last_attempt_at' => $snapshot->validatedAtMs,
                ];
                $this->save($record);

                return true;
            }) === true;
        } catch (Throwable) {
            return false;
        }
    }

    public function forget(string $resource, string $identifier): bool
    {
        if (DeliveryIdentifier::parameters($resource, $identifier) === null || ! $this->supported()) {
            return false;
        }

        try {
            return $this->locked(function () use ($resource, $identifier): bool {
                $record = $this->load();
                unset($record['items'][$this->itemKey($resource, $identifier)]);
                $this->save($record);

                return true;
            }) === true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{available:bool,known:int|null,scheduled_minute:int,last_run_at:?int,error:?string} */
    public function status(): array
    {
        try {
            if (! $this->supported()) {
                throw new RuntimeException('unsupported');
            }
            $record = $this->load();

            return [
                'available' => true,
                'known' => count($record['items']),
                'scheduled_minute' => $this->scheduledMinute(),
                'last_run_at' => $record['last_run_at'],
                'error' => $record['overflowed_at'] === null ? null : 'registry_full',
            ];
        } catch (Throwable) {
            return [
                'available' => false,
                'known' => null,
                'scheduled_minute' => $this->scheduledMinute(),
                'last_run_at' => null,
                'error' => 'reconciliation_unavailable',
            ];
        }
    }

    /** @return array{eligible:bool,checked:int,refreshed:int,failed:int,error:?string} */
    public function runBatch(OnDemandDelivery $delivery, int $maxJobs, int $budgetMs): array
    {
        $summary = ['eligible' => false, 'checked' => 0, 'refreshed' => 0, 'failed' => 0, 'error' => null];
        if ($this->config->get('smking.delivery.mode', 'legacy') !== 'on_demand') {
            $summary['error'] = 'mode_disabled';

            return $summary;
        }
        if ($maxJobs < 1 || $maxJobs > 20 || $budgetMs < 1 || $budgetMs > 10_000 || ! $this->supported()) {
            $summary['error'] = 'configuration_or_storage';

            return $summary;
        }

        try {
            $now = $this->localNow();
            if ($now->format('H') !== '03' || (int) $now->format('i') !== $this->scheduledMinute()) {
                $summary['error'] = 'outside_window';

                return $summary;
            }
            $summary['eligible'] = true;
            $date = $now->format('Y-m-d');
            $began = $this->beginRun($date);
            if ($began === false) {
                $summary['error'] = 'already_ran';

                return $summary;
            }
            if ($began !== true) {
                throw new RuntimeException('delivery_reconciliation_run_write_failed');
            }
            $budget = new WaitBudget($budgetMs);
            $deadline = hrtime(true) + $budgetMs * 1_000_000;
            for ($index = 0; $index < $maxJobs && hrtime(true) < $deadline; $index++) {
                $item = $this->claim($date, $this->cmsNotificationsAvailable());
                if ($item === false) {
                    break;
                }
                if ($item === null) {
                    throw new RuntimeException('delivery_reconciliation_busy');
                }
                $summary['checked']++;
                $result = $delivery->refresh($item['resource'], $item['identifier'], $budget);
                if ($result->snapshot !== null) {
                    $summary['refreshed']++;
                } else {
                    $summary['failed']++;
                }
                if ($result->error === 'budget_exhausted') {
                    break;
                }
            }
            if ($summary['failed'] > 0) {
                $summary['error'] = 'refresh_failed';
            }
        } catch (Throwable) {
            $summary['error'] = 'reconciliation_unavailable';
        }

        return $summary;
    }

    private function beginRun(string $date): ?bool
    {
        $result = $this->locked(function () use ($date): bool {
            $record = $this->load();
            if ($record['last_run_on'] === $date) {
                return false;
            }
            $record['last_run_at'] = ($this->clock)();
            $record['last_run_on'] = $date;
            $this->save($record);

            return true;
        });

        return is_bool($result) ? $result : null;
    }

    /** @return array{resource:string,identifier:string}|false|null */
    private function claim(string $date, bool $skipCms): array|false|null
    {
        return $this->locked(function () use ($date, $skipCms): array|false {
            $record = $this->load();
            foreach ($record['items'] as $key => $item) {
                if ($item['last_checked_on'] === $date
                    || ($skipCms && $item['resource'] === 'cms-page')
                ) {
                    continue;
                }
                $record['items'][$key]['last_checked_on'] = $date;
                $record['items'][$key]['last_attempt_at'] = ($this->clock)();
                $this->save($record);

                return ['resource' => $item['resource'], 'identifier' => $item['identifier']];
            }

            return false;
        });
    }

    /** @return array{format:int,last_run_at:?int,last_run_on:?string,overflowed_at:?int,items:array<string,array{resource:string,identifier:string,last_checked_on:string,last_success_at:int,last_attempt_at:int}>} */
    private function load(): array
    {
        $record = $this->cache->get($this->key());
        if ($record === null) {
            return [
                'format' => self::FORMAT,
                'last_run_at' => null,
                'last_run_on' => null,
                'overflowed_at' => null,
                'items' => [],
            ];
        }
        if (! is_array($record)
            || ($record['format'] ?? null) !== self::FORMAT
            || ! array_key_exists('last_run_at', $record)
            || ($record['last_run_at'] !== null && ! is_int($record['last_run_at']))
            || ! array_key_exists('last_run_on', $record)
            || ($record['last_run_on'] !== null
                && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $record['last_run_on']) !== 1)
            || ! array_key_exists('overflowed_at', $record)
            || ($record['overflowed_at'] !== null && ! is_int($record['overflowed_at']))
            || ! is_array($record['items'] ?? null)
            || count($record['items']) > $this->maxItems
        ) {
            throw new RuntimeException('delivery_reconciliation_invalid');
        }
        foreach ($record['items'] as $key => $item) {
            if (! is_array($item)
                || $key !== $this->itemKey($item['resource'] ?? '', $item['identifier'] ?? '')
                || DeliveryIdentifier::parameters($item['resource'] ?? '', $item['identifier'] ?? '') === null
                || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $item['last_checked_on'] ?? '') !== 1
                || ! is_int($item['last_success_at'] ?? null)
                || ! is_int($item['last_attempt_at'] ?? null)
            ) {
                throw new RuntimeException('delivery_reconciliation_item_invalid');
            }
        }

        return $record;
    }

    private function save(array $record): void
    {
        if ($this->cache->forever($this->key(), $record) !== true) {
            throw new RuntimeException('delivery_reconciliation_write_failed');
        }
    }

    private function localNow(): DateTimeImmutable
    {
        $timezone = $this->config->get('app.timezone', 'UTC');
        if (! is_string($timezone) || $timezone === '') {
            throw new RuntimeException('delivery_reconciliation_timezone_invalid');
        }

        return (new DateTimeImmutable('@'.intdiv(($this->clock)(), 1000)))
            ->setTimezone(new DateTimeZone($timezone));
    }

    private function localDate(int $milliseconds): string
    {
        $timezone = $this->config->get('app.timezone', 'UTC');
        if (! is_string($timezone) || $timezone === '') {
            throw new RuntimeException('delivery_reconciliation_timezone_invalid');
        }

        return (new DateTimeImmutable('@'.intdiv($milliseconds, 1000)))
            ->setTimezone(new DateTimeZone($timezone))
            ->format('Y-m-d');
    }

    private function cmsNotificationsAvailable(): bool
    {
        $scope = $this->config->get('smking.delivery.notifications_scope');

        return $this->config->get('smking.delivery.notifications_enabled', false) === true
            && is_string($scope)
            && preg_match('/^[a-f0-9]{64}$/D', $scope) === 1;
    }

    private function site(): string
    {
        return (string) $this->config->get('smking.api_key').'|'.rtrim((string) $this->config->get('smking.base_url'), '/');
    }

    private function key(): string
    {
        return 'smking:delivery:v2:reconciliation:'.substr(hash('sha256', $this->site()), 0, 24);
    }

    private function itemKey(string $resource, string $identifier): string
    {
        return hash('sha256', $resource.'|'.$identifier);
    }

    private function supported(): bool
    {
        if ($this->config->get('smking.cache.enabled', true) !== true
            || $this->maxItems < 1
            || $this->maxItems > 1000
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
