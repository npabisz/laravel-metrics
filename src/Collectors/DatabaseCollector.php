<?php

namespace Npabisz\LaravelMetrics\Collectors;

use Npabisz\LaravelMetrics\Services\MetricsService;

class DatabaseCollector implements CollectorInterface
{
    public function collect(): array
    {
        $service = app(MetricsService::class);
        $data = $service->flushRedisCounters('db');
        $durations = MetricsService::decodeDurations($data, 'durations');

        return [
            'db_queries_total'  => (int) ($data['queries_total'] ?? 0),
            'db_slow_queries'   => (int) ($data['slow_queries'] ?? 0),
            'db_avg_query_ms'   => MetricsService::avgDuration($durations),
            'db_max_query_ms'   => MetricsService::maxDuration($durations),
        ];
    }
}
