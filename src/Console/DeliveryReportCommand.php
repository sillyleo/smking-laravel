<?php

declare(strict_types=1);

namespace Smking\Laravel\Console;

use Illuminate\Console\Command;
use Smking\Laravel\Delivery\DeliveryReportOutbox;
use Smking\Laravel\Delivery\DeliveryReportTransport;

/** One bounded report tick; it never runs inside a visitor request. */
final class DeliveryReportCommand extends Command
{
    protected $signature = 'smking:delivery:report
        {--prepare : Prepare local sender health without sending}
        {--status : Read local sender health without renewing it}';

    protected $description = 'Send at most one signed smking SDK report';

    public function handle(DeliveryReportOutbox $outbox, DeliveryReportTransport $transport): int
    {
        if ($this->option('prepare') && $this->option('status')) {
            $this->error('--prepare 與 --status 不可同時使用。');

            return self::INVALID;
        }
        if ($this->option('status')) {
            $status = $outbox->status();
            $status['configuration_valid'] = $transport->metadata() !== null;
            $this->line(json_encode($status, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return $status['configuration_valid']
                && $status['error'] === null
                && $status['heartbeat_recent']
                && array_sum($status['losses']) === 0
                ? self::SUCCESS
                : self::FAILURE;
        }
        if (config('smking.delivery.mode', 'legacy') !== 'on_demand' && ! $this->option('prepare')) {
            $this->error('獨立回報尚未啟用。');

            return self::FAILURE;
        }

        $summary = $outbox->runOnce($transport, (bool) $this->option('prepare'));
        $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $summary['error'] === null ? self::SUCCESS : self::FAILURE;
    }
}
