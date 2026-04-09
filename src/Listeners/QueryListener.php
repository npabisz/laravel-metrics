<?php

namespace Npabisz\LaravelMetrics\Listeners;

use Illuminate\Database\Events\QueryExecuted;
use Npabisz\LaravelMetrics\Services\MetricsService;

class QueryListener
{
    protected MetricsService $metrics;

    public function __construct(MetricsService $metrics)
    {
        $this->metrics = $metrics;
    }

    public function handle(QueryExecuted $event): void
    {
        // Ignore queries to the monitoring tables themselves
        if (str_contains($event->sql, 'metrics') && preg_match('/\b(metrics|metrics_slow_logs|metrics_hourly)\b/', $event->sql)) {
            return;
        }

        // Track aggregate metrics
        $this->metrics->increment('db', 'queries_total');
        $this->metrics->pushToList('db', 'durations', round($event->time, 2));

        // Log slow queries to database
        $threshold = config('metrics.slow_query_threshold', 100);

        if ($event->time > $threshold) {
            $this->metrics->increment('db', 'slow_queries');

            $this->metrics->logSlowQuery(
                $event->sql,
                $event->bindings,
                $event->time,
                $event->connectionName
            );
        }
    }
}
