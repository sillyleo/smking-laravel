<?php

declare(strict_types=1);

namespace Smking\Laravel\Delivery;

use Closure;
use Composer\InstalledVersions;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/** One signed v2 POST with exact-byte acknowledgement and no retry. */
final class DeliveryReportTransport
{
    private const MAX_BODY_BYTES = 32_768;

    private const MAX_ACK_BYTES = 16_384;

    private readonly Closure $clock;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly ConfigRepository $config,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => (int) floor(microtime(true) * 1000);
    }

    /** @return array<string, mixed>|null */
    public function metadata(): ?array
    {
        $key = $this->config->get('smking.api_key');
        $baseUrl = $this->config->get('smking.base_url');
        $secret = $this->config->get('smking.webhook_secret');
        $appUrl = $this->config->get('app.url');
        $cacheEnabled = $this->config->get('smking.cache.enabled', true);
        if (! is_string($key)
            || strlen($key) > 256
            || preg_match('/^pk_[A-Za-z0-9_-]+$/D', $key) !== 1
            || ! is_string($secret)
            || $secret === ''
            || strlen($secret) > 1024
            || ! is_string($baseUrl)
            || ! $this->validUrl($baseUrl, remote: true)
            || ! is_string($appUrl)
            || ! $this->validUrl($appUrl, remote: false)
            || $cacheEnabled !== true
        ) {
            return null;
        }

        $url = parse_url($appUrl);
        if (! is_array($url) || ! is_string($url['host'] ?? null)) {
            return null;
        }
        $defaultPort = ($url['scheme'] ?? null) === 'https' ? 443 : 80;
        $host = strtolower($url['host']).(isset($url['port']) && $url['port'] !== $defaultPort ? ':'.$url['port'] : '');
        $mode = $this->config->get('smking.delivery.mode', 'legacy');
        $aeoEnabled = $this->config->get('smking.delivery.aeo_enabled', true);
        $environment = $this->config->get('app.env');
        $mount = $this->config->get('smking.cms.base_path');
        $store = $this->config->get('smking.cache.store') ?? $this->config->get('cache.default');
        $budget = $this->integer('page_budget_ms', 500, 0, 2000);
        $capacity = $this->integer('capacity', 1, 1, 8);
        $maxJobs = $this->integer('work_max_jobs', 10, 1, 20);
        $workBudget = $this->integer('work_budget_ms', 5000, 1, 10_000);
        $heartbeat = $this->integer('heartbeat_seconds', 180, 30, 900);
        $connect = $this->config->get('smking.delivery.connect_timeout', 0.5);
        $connect = is_numeric($connect) ? (float) $connect : NAN;
        $version = InstalledVersions::isInstalled('smking/laravel')
            ? InstalledVersions::getVersion('smking/laravel')
            : null;

        if (! in_array($mode, ['legacy', 'on_demand'], true)
            || ! is_bool($aeoEnabled)
            || ($environment !== null && (! is_string($environment) || strlen($environment) > 32))
            || ($mount !== null && (! is_string($mount) || ! DeliveryReportPayload::safePath($mount)))
            || ($store !== null && (! is_string($store) || strlen($store) > 64))
            || $budget === null
            || $capacity === null
            || $maxJobs === null
            || $workBudget === null
            || $heartbeat === null
            || ! is_finite($connect)
            || $connect <= 0
            || $connect > 10
            || strlen($host) > 256
            || ($version !== null && strlen($version) > 64)
        ) {
            return null;
        }

        return [
            'sdk' => 'laravel',
            'sdk_version' => $version,
            'app_env' => $environment,
            'host' => $host,
            'cms_base_path' => $mount,
            'cache_store' => $store,
            'cache_enabled' => $cacheEnabled,
            'delivery' => [
                'mode' => $mode,
                'aeo_enabled' => $aeoEnabled,
                'page_budget_ms' => $budget,
                'connect_timeout_ms' => (int) round($connect * 1000),
                'capacity_per_host' => $capacity,
                'work_max_jobs' => $maxJobs,
                'work_budget_ms' => $workBudget,
                'heartbeat_seconds' => $heartbeat,
            ],
        ];
    }

    /** @param array<string, array<string, mixed>> $events
     *  @param array<string, array<string, mixed>> $observations
     *  @param array{transport:int,upstream:int,capacity:int} $failures
     */
    public function body(string $reportId, int $nowMs, array $events, array $observations, array $failures): ?string
    {
        $metadata = $this->metadata();
        if ($metadata === null || count($events) + count($observations) > 20) {
            return null;
        }
        foreach ($events as $event) {
            if (! DeliveryReportPayload::validHit($event)) {
                return null;
            }
        }
        foreach ($observations as $observation) {
            if (! DeliveryReportPayload::validObservation($observation)) {
                return null;
            }
        }
        if (array_keys($failures) !== ['transport', 'upstream', 'capacity']
            || array_filter($failures, static fn ($count): bool => ! is_int($count) || $count < 0 || $count > 1_000_000) !== []
        ) {
            return null;
        }

        try {
            $body = json_encode([
                'key' => $this->config->get('smking.api_key'),
                'report_id' => $reportId,
                'observed_at' => DeliveryReportPayload::timestamp($nowMs),
                'sdk_meta' => $metadata,
                'paths' => [],
                'path_observations' => array_values($observations),
                'failures' => $failures,
                'crawler_hits' => array_values($events),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return strlen($body) <= self::MAX_BODY_BYTES ? $body : null;
    }

    /** @return array{accepted:bool,error:?string,retry_after:int} */
    public function send(string $body): array
    {
        $failure = static fn (string $error, int $retryAfter = 60): array => [
            'accepted' => false,
            'error' => $error,
            'retry_after' => $retryAfter,
        ];
        $metadata = $this->metadata();
        if (PHP_SAPI !== 'cli' || $metadata === null || strlen($body) > self::MAX_BODY_BYTES) {
            return $failure('configuration');
        }

        try {
            $sent = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            if (! $this->validBody($sent, $metadata)) {
                return $failure('configuration');
            }

            $signature = 'sha256='.hash_hmac(
                'sha256',
                "smking-sdk-report-v2\n".$body,
                (string) $this->config->get('smking.webhook_secret'),
            );
            $response = $this->http->withOptions([
                'timeout' => 1.0,
                'connect_timeout' => 0.5,
                'read_timeout' => 1.0,
                'allow_redirects' => false,
                'http_errors' => false,
                'on_headers' => static function (ResponseInterface $response): void {
                    if ((int) $response->getHeaderLine('Content-Length') > self::MAX_ACK_BYTES) {
                        throw new RuntimeException('delivery_report_ack_too_large');
                    }
                },
                'progress' => static function ($total, $downloaded): void {
                    if ($downloaded > self::MAX_ACK_BYTES) {
                        throw new RuntimeException('delivery_report_ack_too_large');
                    }
                },
            ])->acceptJson()
                ->withHeaders(['X-Smking-Report-Signature' => $signature])
                ->withBody($body, 'application/json')
                ->post(rtrim((string) $this->config->get('smking.base_url'), '/').'/api/v2/sdk/report');

            $retryHeader = $response->header('Retry-After');
            $retryAfter = is_string($retryHeader) && ctype_digit($retryHeader)
                ? min(300, max(60, (int) $retryHeader))
                : 60;
            if ($response->status() !== 200) {
                return $failure('http_'.$response->status(), $retryAfter);
            }
            if (strtolower(trim(explode(';', $response->header('Content-Type'))[0])) !== 'application/json'
                || strlen($response->body()) > self::MAX_ACK_BYTES
            ) {
                return $failure('invalid_ack');
            }

            $ack = $response->json();
            if (! is_array($ack)
                || ($ack['status'] ?? null) !== 'accepted'
                || ($ack['report_id'] ?? null) !== ($sent['report_id'] ?? null)
                || ! is_bool($ack['state_applied'] ?? null)
                || ! $this->sameIds($ack['accepted_event_ids'] ?? null, $sent['crawler_hits'] ?? [])
                || ! $this->sameIds($ack['accepted_observation_ids'] ?? null, $sent['path_observations'] ?? [], 'observation_id')
            ) {
                return $failure('invalid_ack');
            }

            return ['accepted' => true, 'error' => null, 'retry_after' => 60];
        } catch (Throwable) {
            return $failure('transport');
        }
    }

    /** @param mixed $accepted
     *  @param mixed $sent
     */
    private function sameIds(mixed $accepted, mixed $sent, string $field = 'event_id'): bool
    {
        if (! is_array($accepted) || ! array_is_list($accepted) || ! is_array($sent) || ! array_is_list($sent)) {
            return false;
        }
        $expected = array_column($sent, $field);
        sort($accepted);
        sort($expected);

        return $accepted === $expected;
    }

    /** @param array<string, mixed> $metadata */
    private function validBody(mixed $body, array $metadata): bool
    {
        if (! is_array($body)) {
            return false;
        }
        $keys = array_keys($body);
        $expectedKeys = [
            'crawler_hits',
            'failures',
            'key',
            'observed_at',
            'path_observations',
            'paths',
            'report_id',
            'sdk_meta',
        ];
        sort($keys);
        sort($expectedKeys);
        if ($keys !== $expectedKeys
            || ($body['key'] ?? null) !== $this->config->get('smking.api_key')
            || ($body['sdk_meta'] ?? null) !== $metadata
            || ($body['paths'] ?? null) !== []
            || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $body['report_id'] ?? '') !== 1
            || ! is_string($body['observed_at'] ?? null)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D', $body['observed_at']) !== 1
            || ! is_array($body['crawler_hits'] ?? null)
            || ! array_is_list($body['crawler_hits'])
            || ! is_array($body['path_observations'] ?? null)
            || ! array_is_list($body['path_observations'])
            || count($body['crawler_hits']) + count($body['path_observations']) > 20
            || ! is_array($body['failures'] ?? null)
            || array_keys($body['failures']) !== ['transport', 'upstream', 'capacity']
        ) {
            return false;
        }
        $observedAt = (int) floor((float) (new \DateTimeImmutable($body['observed_at']))->format('U.u') * 1000);
        $now = ($this->clock)();
        if ($observedAt <= $now - 600_000 || $observedAt > $now + 30_000) {
            return false;
        }

        $eventIds = [];
        foreach ($body['crawler_hits'] as $event) {
            if (! DeliveryReportPayload::validHit($event) || isset($eventIds[$event['event_id']])) {
                return false;
            }
            $eventIds[$event['event_id']] = true;
        }
        $observationIds = [];
        foreach ($body['path_observations'] as $observation) {
            if (! DeliveryReportPayload::validObservation($observation)
                || isset($observationIds[$observation['observation_id']])
            ) {
                return false;
            }
            $observationIds[$observation['observation_id']] = true;
        }
        foreach ($body['failures'] as $count) {
            if (! is_int($count) || $count < 0 || $count > 1_000_000) {
                return false;
            }
        }

        return true;
    }

    private function integer(string $name, int $default, int $minimum, int $maximum): ?int
    {
        $value = $this->config->get('smking.delivery.'.$name, $default);
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        return is_int($value) && $value >= $minimum && $value <= $maximum ? $value : null;
    }

    private function validUrl(string $value, bool $remote): bool
    {
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $value)) {
            return false;
        }
        $url = parse_url($value);
        if (! is_array($url)
            || empty($url['host'])
            || isset($url['user'])
            || isset($url['pass'])
            || isset($url['query'])
            || isset($url['fragment'])
        ) {
            return false;
        }
        if (($url['scheme'] ?? null) === 'https') {
            return true;
        }

        return ($url['scheme'] ?? null) === 'http'
            && (! $remote || (in_array($url['host'], ['127.0.0.1', 'localhost', '[::1]'], true)
                && in_array($this->config->get('app.env'), ['local', 'testing'], true)));
    }
}
