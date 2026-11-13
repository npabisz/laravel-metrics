<?php

namespace Npabisz\LaravelMetrics\Middleware;

use Closure;
use Illuminate\Http\Request;
use Npabisz\LaravelMetrics\Services\MetricsService;
use Npabisz\LaravelMetrics\Trace\RequestTrace;
use Symfony\Component\HttpFoundation\Response;

class RequestMonitor
{
    protected MetricsService $metrics;

    protected RequestTrace $trace;

    public function __construct(MetricsService $metrics, RequestTrace $trace)
    {
        $this->metrics = $metrics;
        $this->trace = $trace;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);
        $tracing = (bool) config('metrics.trace.enabled', true);

        if ($tracing) {
            $this->trace->start($start, $this->requestTime($request));
        }

        try {
            $response = $next($request);
            $end = microtime(true);
            $durationMs = ($end - $start) * 1000;
            $threshold = $this->getThreshold($request);
            // Taken before the trace ends, and only for a request worth logging.
            $trace = $tracing && $durationMs > $threshold ? $this->traceContext($end) : [];
        } finally {
            if ($tracing) {
                $this->trace->end();
            }
        }

        $statusCode = $response->getStatusCode();

        // Increment Redis counters for aggregate metrics
        $this->metrics->increment('http', 'requests_total');
        $this->metrics->pushToList('http', 'durations', round($durationMs, 2));

        if ($statusCode >= 200 && $statusCode < 300) {
            $this->metrics->increment('http', 'requests_2xx');
        } elseif ($statusCode >= 400 && $statusCode < 500) {
            $this->metrics->increment('http', 'requests_4xx');
        } elseif ($statusCode >= 500) {
            $this->metrics->increment('http', 'requests_5xx');
        }

        if ($durationMs > $threshold) {
            $this->metrics->increment('http', 'slow_requests');

            $route = $request->route();

            $this->metrics->logSlowRequest(
                $request->method(),
                $request->fullUrl(),
                $route ? $route->uri() : null,
                $statusCode,
                $durationMs,
                $request->user()?->id,
                [
                    'threshold' => $threshold,
                    'ip'        => $request->ip(),
                    'user_agent' => mb_substr($request->userAgent() ?? '', 0, 200),
                ] + $trace
            );
        }

        // Add timing header
        $response->headers->set('X-Response-Time', round($durationMs) . 'ms');

        return $response;
    }

    protected function getThreshold(Request $request): int
    {
        $thresholds = config('metrics.slow_request_thresholds', ['*' => 2000]);
        $path = $request->path();

        foreach ($thresholds as $pattern => $threshold) {
            if ($pattern === '*') {
                continue;
            }

            if (fnmatch($pattern, $path)) {
                return $threshold;
            }
        }

        return $thresholds['*'] ?? 2000;
    }

    /**
     * What the trace adds to a slow request's context.
     */
    protected function traceContext(float $end): array
    {
        if (!$this->trace->isActive()) {
            return [];
        }

        return [
            'trace_id' => $this->trace->id(),
            'trace'    => $this->trace->snapshot($end),
        ];
    }

    /**
     * When the server received the request, if it says so.
     */
    protected function requestTime(Request $request): ?float
    {
        $time = $request->server('REQUEST_TIME_FLOAT');

        return is_numeric($time) ? (float) $time : null;
    }
}
