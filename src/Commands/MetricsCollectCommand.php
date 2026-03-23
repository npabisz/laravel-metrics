<?php

namespace Npabisz\LaravelMetrics\Commands;

use Illuminate\Console\Command;
use Npabisz\LaravelMetrics\Services\MetricsService;

class MetricsCollectCommand extends Command
{
    protected $signature = 'monitoring:collect';
    protected $description = 'Collect monitoring metrics and store in database';

    public function handle(MetricsService $service): int
    {
        if (!config('metrics.enabled', true)) {
            $this->info('Monitoring is disabled.');
            return self::SUCCESS;
        }

        $record = $service->collectAndStore();

        $this->info(sprintf(
            'Metrics collected [ID: %d] — HTTP: %d req, Queue: %d/%d/%d, DB: %d queries (%d slow), Redis: %sMB',
            $record->id,
            $record->http_requests_total,
            $record->queue_depth_high,
            $record->queue_depth_default,
            $record->queue_depth_low,
            $record->db_queries_total,
            $record->db_slow_queries,
            $record->redis_memory_used_mb ?? '?'
        ));

        return self::SUCCESS;
    }
}
