<?php

declare(strict_types=1);

namespace Smking\Laravel\Delivery;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Throwable;

/** Authenticated content target registration; retains the original CMS wire contract. */
final class CmsDeliveryNotification
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly DeliveryTargetState $targets,
        private readonly DeliveryWorklist $worklist,
        private readonly OnDemandDelivery $delivery,
        private readonly LegacyCmsCache $legacy,
        private readonly ?DeliveryNotificationHealth $health = null,
    ) {
    }

    public function receive(array $payload, int $bodyBytes): JsonResponse
    {
        if (! $this->targets->available()) {
            return $this->error('notifications_unavailable', 503);
        }
        if (! $this->validEnvelope($payload, $bodyBytes)) {
            return $this->error('invalid_notification', 400);
        }
        if (! $this->fresh($payload['deliveredAt'])) {
            return $this->error('stale_delivery', 401);
        }

        $source = rtrim((string) $this->config->get('smking.base_url'), '/');
        $key = (string) $this->config->get('smking.api_key');
        $scope = (string) $this->config->get('smking.delivery.notifications_scope');
        foreach ([
            'sourceUrl' => $source,
            'keyFingerprint' => hash('sha256', $key),
            'scope' => $scope,
        ] as $field => $expected) {
            if (! is_string($payload[$field]) || ! hash_equals($expected, $payload[$field])) {
                return $this->error('notification_scope_mismatch', 401);
            }
        }

        if ($payload['kind'] === 'content_delivery_probe_v2') {
            $work = $this->worklist->status();
            if (! $work['heartbeat_recent'] || $work['error'] !== null) {
                return $this->error('background_unavailable', 503);
            }
            return response()->json([
                'ok' => true, 'kind' => $payload['kind'], 'contract' => '2',
                'deliveryId' => $payload['deliveryId'], 'sourceUrl' => $source,
                'scope' => $scope, 'keyFingerprint' => hash('sha256', $key),
                'resources' => $payload['resources'],
            ])->header('Cache-Control', 'no-store');
        }

        $normalized = [];
        foreach ($payload['targets'] as $candidate) {
            $target = is_array($candidate) ? DeliveryTargetState::normalize($candidate) : null;
            if ($target === null
                || ($payload['kind'] === 'cms_delivery_v2' && $target['resource'] !== 'cms-page')
            ) {
                return $this->error('invalid_notification', 400);
            }
            $identifier = $target['resource'].'|'.$target['identifier'];
            if (isset($normalized[$identifier])) {
                return $this->error('invalid_notification', 400);
            }
            $normalized[$identifier] = $target;
        }

        $statusCode = 200;
        $results = [];
        foreach ($normalized as $target) {
            $applied = $this->targets->apply($target);
            $status = $applied['status'];
            if ($status === 'invalid' || $status === 'conflict') {
                $statusCode = 409;
            } elseif ($status === 'unavailable') {
                if ($statusCode !== 409) {
                    $statusCode = 503;
                }
            } elseif ($status !== 'obsolete') {
                if ($target['action'] === 'withdraw') {
                    // A later publish may already have superseded this withdrawal.
                    // Never clear its body after releasing the target apply lock.
                    try {
                        $invalidated = $this->targets->guard($target['identifier'], $applied['record']['token'], function () use ($target): string {
                            $legacy = $target['resource'] !== 'cms-page'
                                || $this->legacy->invalidate(substr($target['identifier'], 5));
                            return $legacy && $this->delivery->invalidate($target['resource'], $target['identifier'])
                                ? 'withdrawn' : 'unavailable';
                        }, $target['resource']);
                    } catch (Throwable) {
                        $invalidated = 'unavailable';
                    }
                    if ($invalidated === 'withdrawn' || $invalidated === false) {
                        $status = $invalidated === false ? 'obsolete' : 'withdrawn';
                    } else {
                        $status = 'unavailable';
                        if ($statusCode !== 409) {
                            $statusCode = 503;
                        }
                    }
                } elseif ($this->worklist->schedule($target['resource'], $target['identifier'])) {
                    // A normal update keeps the last usable body visible until
                    // the worker validates and atomically commits this target.
                    $status = 'registered';
                } else {
                    $status = 'unavailable';
                    if ($statusCode !== 409) {
                        $statusCode = 503;
                    }
                }
            }
            $results[] = ['target' => $target, 'status' => $status];
        }

        $resources = [];
        foreach ($results as $result) {
            if ($result['status'] === 'obsolete') {
                continue;
            }
            $resource = $result['target']['resource'];
            $resources[$resource] = ($resources[$resource] ?? true)
                && in_array($result['status'], ['registered', 'withdrawn', 'current'], true);
        }
        foreach ($resources as $resource => $accepted) {
            if ($this->health !== null && ! $this->health->record($resource, $accepted)) {
                return $this->error('notification_health_unavailable', 503);
            }
        }

        return response()->json([
            'ok' => $statusCode === 200,
            'kind' => $payload['kind'],
            'contract' => '2',
            'deliveryId' => $payload['deliveryId'],
            'sourceUrl' => $source,
            'scope' => $scope,
            'keyFingerprint' => hash('sha256', $key),
            'results' => $results,
        ], $statusCode)->header('Cache-Control', 'no-store');
    }

    private function validEnvelope(array $payload, int $bodyBytes): bool
    {
        if ($bodyBytes < 1 || $bodyBytes > 65_536) {
            return false;
        }
        $keys = array_keys($payload);
        $probe = ($payload['kind'] ?? null) === 'content_delivery_probe_v2';
        $expected = [
            'contract',
            'deliveredAt',
            'deliveryId',
            'keyFingerprint',
            'kind',
            'scope',
            'sourceUrl',
            $probe ? 'resources' : 'targets',
        ];
        sort($keys);
        sort($expected);

        return $keys === $expected
            && in_array($payload['kind'], ['cms_delivery_v2', 'content_delivery_v2', 'content_delivery_probe_v2'], true)
            && $payload['contract'] === '2'
            && is_string($payload['deliveryId'])
            && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $payload['deliveryId']) === 1
            && is_string($payload['deliveredAt'])
            && ($probe
                ? is_array($payload['resources']) && array_is_list($payload['resources'])
                    && count($payload['resources']) >= 1 && count($payload['resources']) <= 4
                    && count(array_unique($payload['resources'], SORT_REGULAR)) === count($payload['resources'])
                    && count(array_filter($payload['resources'], static fn ($resource): bool => in_array($resource, DeliveryNotificationHealth::RESOURCES, true))) === count($payload['resources'])
                : is_array($payload['targets']) && array_is_list($payload['targets'])
                    && count($payload['targets']) >= 1 && count($payload['targets']) <= 16);
    }

    private function fresh(string $timestamp): bool
    {
        try {
            $date = DateTimeImmutable::createFromFormat(
                '!Y-m-d\TH:i:s.v\Z',
                $timestamp,
                new DateTimeZone('UTC'),
            );

            return $date !== false
                && $date->format('Y-m-d\TH:i:s.v\Z') === $timestamp
                && abs(time() - $date->getTimestamp()) <= 300;
        } catch (Throwable) {
            return false;
        }
    }

    private function error(string $error, int $status): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => $error], $status)
            ->header('Cache-Control', 'no-store');
    }
}
