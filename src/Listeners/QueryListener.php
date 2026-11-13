<?php

namespace Npabisz\LaravelMetrics\Listeners;

use Illuminate\Database\Events\QueryExecuted;
use Npabisz\LaravelMetrics\Services\MetricsService;
use Npabisz\LaravelMetrics\Trace\RequestTrace;

class QueryListener
{
    protected MetricsService $metrics;

    protected RequestTrace $trace;

    public function __construct(MetricsService $metrics, RequestTrace $trace)
    {
        $this->metrics = $metrics;
        $this->trace = $trace;
    }

    public function handle(QueryExecuted $event): void
    {
        // Ignore queries to the monitoring tables themselves
        if (str_contains($event->sql, 'metrics') && preg_match('/\b(metrics|metrics_slow_logs|metrics_hourly)\b/', $event->sql)) {
            return;
        }

        // Does nothing outside a traced request
        $this->trace->query($event->sql, $event->time);

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
                $event->connectionName,
                // Links the query to the request it ran in, should that
                // request be logged too.
                $this->trace->isActive() ? ['trace_id' => $this->trace->id()] : null
            );
        }
    }
}
