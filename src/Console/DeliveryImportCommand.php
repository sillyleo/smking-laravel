<?php

declare(strict_types=1);

namespace Smking\Laravel\Console;

use Illuminate\Console\Command;
use Smking\Laravel\Delivery\DeliveryIdentifier;
use Smking\Laravel\Delivery\OnDemandDelivery;

/** Explicit known-target upgrade only. No scans, remote requests or mode changes. */
final class DeliveryImportCommand extends Command
{
    protected $signature = 'smking:delivery:import
        {--resource= : cms-page, aeo, markdown or site-file}
        {--identifier=* : Explicit existing v2 identifiers; at most 20 per invocation}';

    protected $description = 'Import known v2 cache records and fences into private persistent storage';

    public function handle(OnDemandDelivery $delivery): int
    {
        $resource = $this->option('resource');
        $identifiers = $this->option('identifier');
        $summary = ['processed' => 0, 'ready' => 0, 'items' => [], 'error' => null];
        if (! is_string($resource) || ! is_array($identifiers) || $identifiers === [] || count($identifiers) > 20) {
            $summary['error'] = 'invalid_input';
        } else {
            foreach ($identifiers as $identifier) {
                if (! is_string($identifier) || DeliveryIdentifier::parameters($resource, $identifier) === null) {
                    $summary['error'] = 'invalid_input';
                    break;
                }
            }
        }
        if ($summary['error'] === null) {
            $deadline = hrtime(true) + 10_000_000_000;
            foreach (array_unique($identifiers) as $identifier) {
                if (hrtime(true) >= $deadline) {
                    $summary['error'] = 'budget_exhausted';
                    break;
                }
                $result = $delivery->importCached($resource, $identifier);
                $ready = $result->snapshot !== null && $result->httpStatus === 200 && $result->error === null;
                $summary['processed']++;
                $summary['ready'] += (int) $ready;
                $summary['items'][] = ['identifier' => $identifier, 'state' => $ready ? 'ready' : ($result->error ?? 'not_ready')];
                if (! $ready) {
                    $summary['error'] ??= 'content_not_ready';
                }
            }
        }
        $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $summary['error'] === null ? self::SUCCESS : self::FAILURE;
    }
}
