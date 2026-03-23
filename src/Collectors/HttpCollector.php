<?php

namespace Npabisz\LaravelMetrics\Collectors;

use Npabisz\LaravelMetrics\Services\MetricsService;

class HttpCollector implements CollectorInterface
{
    public function collect(): array
    {
        $service = app(MetricsService::class);
        $data = $service->flushRedisCounters('http');
        $durations = MetricsService::decodeDurations($data, 'durations');

        return [
            'http_requests_total'    => (int) ($data['requests_total'] ?? 0),
            'http_requests_2xx'      => (int) ($data['requests_2xx'] ?? 0),
            'http_requests_4xx'      => (int) ($data['requests_4xx'] ?? 0),
            'http_requests_5xx'      => (int) ($data['requests_5xx'] ?? 0),
            'http_avg_duration_ms'   => MetricsService::avgDuration($durations),
            'http_max_duration_ms'   => MetricsService::maxDuration($durations),
            'http_slow_requests'     => (int) ($data['slow_requests'] ?? 0),
        ];
    }
}
