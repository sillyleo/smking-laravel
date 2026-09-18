<?php

declare(strict_types=1);

namespace Smking\Laravel\Delivery;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Throwable;

/** Authenticated CMS v2 target registration; it never downloads content. */
final class CmsDeliveryNotification
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly DeliveryTargetState $targets,
        private readonly DeliveryWorklist $worklist,
        private readonly OnDemandDelivery $delivery,
        private readonly LegacyCmsCache $legacy,
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

        $normalized = [];
        foreach ($payload['targets'] as $candidate) {
            $target = is_array($candidate) ? DeliveryTargetState::normalize($candidate) : null;
            if ($target === null) {
                return $this->error('invalid_notification', 400);
            }
            $identifier = $target['identifier'];
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
                    $slug = substr($target['identifier'], 5);
                    $invalidated = $this->legacy->invalidate($slug)
                        && $this->delivery->invalidate('cms-page', $target['identifier']);
                    if ($invalidated) {
                        $status = 'withdrawn';
                    } else {
                        $status = 'unavailable';
                        if ($statusCode !== 409) {
                            $statusCode = 503;
                        }
                    }
                } elseif ($this->worklist->schedule('cms-page', $target['identifier'])) {
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

        return response()->json([
            'ok' => $statusCode === 200,
            'kind' => 'cms_delivery_v2',
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
        $expected = [
            'contract',
            'deliveredAt',
            'deliveryId',
            'keyFingerprint',
            'kind',
            'scope',
            'sourceUrl',
            'targets',
        ];
        sort($keys);
        sort($expected);

        return $keys === $expected
            && $payload['kind'] === 'cms_delivery_v2'
            && $payload['contract'] === '2'
            && is_string($payload['deliveryId'])
            && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $payload['deliveryId']) === 1
            && is_string($payload['deliveredAt'])
            && is_array($payload['targets'])
            && array_is_list($payload['targets'])
            && count($payload['targets']) >= 1
            && count($payload['targets']) <= 16;
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
