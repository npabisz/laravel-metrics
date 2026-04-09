<?php

namespace Npabisz\LaravelMetrics\Middleware;

use Closure;
use Illuminate\Http\Request;
use Npabisz\LaravelMetrics\Services\MetricsService;
use Symfony\Component\HttpFoundation\Response;

class RequestMonitor
{
    protected MetricsService $metrics;

    public function __construct(MetricsService $metrics)
    {
        $this->metrics = $metrics;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);

        $response = $next($request);

        $durationMs = (microtime(true) - $start) * 1000;
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

        // Check if this is a slow request
        $threshold = $this->getThreshold($request);

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
                ]
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
}
