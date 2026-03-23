<?php

namespace Npabisz\LaravelMetrics\Collectors;

use Illuminate\Support\Facades\Redis;

class QueueCollector implements CollectorInterface
{
    public function collect(): array
    {
        $connection = config('metrics.redis_connection', 'default');
        $metrics = [];

        // Queue depths — read current Redis list lengths
        $queues = [
            'queue_depth_high'    => 'queues:high-priority',
            'queue_depth_default' => 'queues:default',
            'queue_depth_low'     => 'queues:low-priority',
        ];

        foreach ($queues as $metric => $queue) {
            try {
                $metrics[$metric] = (int) Redis::connection($connection)->llen($queue);
            } catch (\Throwable $e) {
                $metrics[$metric] = 0;
            }
        }

        // Jobs processed/failed from Redis counters
        $service = app(\Npabisz\LaravelMetrics\Services\MetricsService::class);
        $data = $service->flushRedisCounters('queue');

        $metrics['queue_jobs_processed'] = (int) ($data['jobs_processed'] ?? 0);
        $metrics['queue_jobs_failed'] = (int) ($data['jobs_failed'] ?? 0);

        return $metrics;
    }
}
