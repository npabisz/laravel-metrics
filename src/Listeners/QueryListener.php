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

            $arguments = [
                $event->sql,
                $event->bindings,
                $event->time,
                $event->connectionName,
                // Links the query to the request it ran in, should that
                // request be logged too.
                $this->trace->isActive() ? ['trace_id' => $this->trace->id()] : null,
            ];

            if ($this->canWriteNow($event)) {
                $this->metrics->logSlowQuery(...$arguments);
            } else {
                $this->metrics->deferSlowQuery(...$arguments);
            }
        }
    }

    /**
     * Whether the slow log row can be inserted while this event is handled.
     * On the query's own connection it cannot right after an INSERT or
     * REPLACE - Laravel reads that statement's insert ID once the event has
     * been handled, and would get the log row's - or inside a transaction,
     * whose rollback would take the row with it. Only statements known not
     * to insert are written at once; anything else waits.
     */
    protected function canWriteNow(QueryExecuted $event): bool
    {
        if ($event->connectionName !== $this->metrics->slowLogConnection()) {
            return true;
        }

        if ($event->connection->transactionLevel() > 0) {
            return false;
        }

        return preg_match('/^\s*(select|update|delete)\b/i', $event->sql) === 1;
    }
}
