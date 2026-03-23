<?php

namespace Npabisz\LaravelMetrics\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Npabisz\LaravelMetrics\Models\Metric;
use Npabisz\LaravelMetrics\Models\MetricHourly;
use Npabisz\LaravelMetrics\Models\SlowLog;
use Npabisz\LaravelMetrics\Services\MetricAggregator;

class DashboardController extends Controller
{
    public function index()
    {
        $views = config('metrics.dashboard.views', []);

        $metricConfigs = [];
        foreach ($views as $view) {
            foreach ($view['sections'] ?? [] as $section) {
                if (!empty($section['keys'])) {
                    $metricConfigs[] = [
                        'label'  => $section['label'] ?? '',
                        'keys'   => $section['keys'],
                        'colors' => $section['colors'] ?? null,
                        'type'   => $section['chart_type'] ?? null,
                        'gauge'  => $section['gauge'] ?? false,
                        'format' => $section['format'] ?? null,
                    ];
                }
            }
        }

        return view('metrics::dashboard', [
            'views'        => $views,
            'metricConfigs' => $metricConfigs,
        ]);
    }

    public function apiData(Request $request): JsonResponse
    {
        $minutes = (int) $request->input('minutes', 60);
        $minutes = min($minutes, 43200); // max 30 days
        $since = now()->subMinutes($minutes);

        // Pick resolution: raw (<2h), 5-min grouped (2-48h), hourly (>48h)
        if ($minutes > 2880) {
            return $this->apiDataHourly($minutes, $since);
        }

        $metrics = Metric::where('recorded_at', '>=', $since)
            ->orderBy('recorded_at', 'asc')
            ->get();

        if ($metrics->isEmpty()) {
            return response()->json(['status' => 'no_data']);
        }

        if ($minutes > 120) {
            return $this->apiDataGrouped($metrics, $minutes, 5);
        }

        return $this->buildResponse($metrics, $minutes, 'H:i');
    }

    /**
     * Raw or grouped response from metrics.
     */
    protected function buildResponse($metrics, int $minutes, string $timeFormat, bool $isGrouped = false): JsonResponse
    {
        $latest = $metrics->last();
        $totalRequests = $metrics->sum('http_requests_total');
        $total5xx = $metrics->sum('http_requests_5xx');

        return response()->json([
            'status'  => 'ok',
            'period'  => $minutes,
            'samples' => $metrics->count(),
            'summary' => [
                'http_requests_total' => $totalRequests,
                'http_requests_2xx'   => $metrics->sum('http_requests_2xx'),
                'http_requests_4xx'   => $metrics->sum('http_requests_4xx'),
                'http_requests_5xx'   => $total5xx,
                'http_avg_duration'   => round($metrics->avg('http_avg_duration_ms'), 1),
                'http_max_duration'   => round($metrics->max('http_max_duration_ms'), 1),
                'http_slow_requests'  => $metrics->sum('http_slow_requests'),
                'error_rate'          => $totalRequests > 0 ? round($total5xx / $totalRequests * 100, 2) : 0,
                'queue_depth_high'    => $latest->queue_depth_high,
                'queue_depth_default' => $latest->queue_depth_default,
                'queue_depth_low'     => $latest->queue_depth_low,
                'queue_jobs_processed' => $metrics->sum('queue_jobs_processed'),
                'queue_jobs_failed'   => $metrics->sum('queue_jobs_failed'),
                'db_queries_total'    => $metrics->sum('db_queries_total'),
                'db_slow_queries'     => $metrics->sum('db_slow_queries'),
                'db_avg_query_ms'     => round($metrics->avg('db_avg_query_ms'), 1),
                'db_max_query_ms'     => round($metrics->max('db_max_query_ms'), 1),
                'redis_memory_mb'     => $latest->redis_memory_used_mb,
                'redis_clients'       => $latest->redis_connected_clients,
                'redis_ops_per_sec'   => $latest->redis_ops_per_sec,
                'redis_hit_rate'      => $latest->redis_cache_hit_rate !== null ? round($latest->redis_cache_hit_rate * 100, 1) : null,
                'cpu_load'            => $latest->cpu_load_1m,
                'memory_mb'           => $latest->memory_usage_mb,
                'disk_free_gb'        => $latest->disk_free_gb,
                'custom'              => $this->aggregateCustomMetrics($metrics),
            ],
            'timeline' => $metrics->map(function ($m) use ($timeFormat) {
                $row = [
                    'time'             => $m->recorded_at->format($timeFormat),
                    'requests'         => $m->http_requests_total,
                    'avg_duration'     => round($m->http_avg_duration_ms, 1),
                    'max_duration'     => round($m->http_max_duration_ms, 1),
                    'errors_5xx'       => $m->http_requests_5xx,
                    'queue_high'       => $m->queue_depth_high,
                    'queue_default'    => $m->queue_depth_default,
                    'queue_low'        => $m->queue_depth_low,
                    'db_queries'       => $m->db_queries_total,
                    'db_slow'          => $m->db_slow_queries,
                    'cpu'              => $m->cpu_load_1m,
                    'redis_memory'     => $m->redis_memory_used_mb,
                    'redis_ops'        => $m->redis_ops_per_sec,
                ];

                if (is_array($m->custom)) {
                    foreach ($m->custom as $key => $value) {
                        if (is_numeric($value)) {
                            $row['c:' . $key] = $value;
                        }
                    }
                }

                return $row;
            })->values(),
        ]);
    }

    /**
     * Group 1-min samples into N-min buckets for mid-range periods.
     */
    protected function apiDataGrouped($metrics, int $minutes, int $bucketMinutes): JsonResponse
    {
        $aggregator = app(MetricAggregator::class);

        $grouped = $metrics->groupBy(function ($m) use ($bucketMinutes) {
            $ts = $m->recorded_at;
            $bucket = $ts->copy()->minute(intdiv($ts->minute, $bucketMinutes) * $bucketMinutes)->second(0);

            return $bucket->format('Y-m-d H:i');
        });

        $timeFormat = $minutes > 1440 ? 'M d H:i' : 'H:i';

        $buckets = $grouped->map(function ($samples, $bucketKey) use ($aggregator, $timeFormat) {
            $latest = $samples->last();
            $customAll = $samples->pluck('custom')->filter()->values();
            $custom = $customAll->isNotEmpty() ? $aggregator->aggregateCustom($customAll) : [];

            $obj = new \stdClass();
            $obj->recorded_at = $samples->first()->recorded_at;
            $obj->http_requests_total = $samples->sum('http_requests_total');
            $obj->http_requests_2xx = $samples->sum('http_requests_2xx');
            $obj->http_requests_4xx = $samples->sum('http_requests_4xx');
            $obj->http_requests_5xx = $samples->sum('http_requests_5xx');
            $obj->http_avg_duration_ms = round($samples->avg('http_avg_duration_ms'), 1);
            $obj->http_max_duration_ms = round($samples->max('http_max_duration_ms'), 1);
            $obj->http_slow_requests = $samples->sum('http_slow_requests');
            $obj->queue_depth_high = $latest->queue_depth_high;
            $obj->queue_depth_default = $latest->queue_depth_default;
            $obj->queue_depth_low = $latest->queue_depth_low;
            $obj->queue_jobs_processed = $samples->sum('queue_jobs_processed');
            $obj->queue_jobs_failed = $samples->sum('queue_jobs_failed');
            $obj->db_queries_total = $samples->sum('db_queries_total');
            $obj->db_slow_queries = $samples->sum('db_slow_queries');
            $obj->db_avg_query_ms = round($samples->avg('db_avg_query_ms'), 1);
            $obj->db_max_query_ms = round($samples->max('db_max_query_ms'), 1);
            $obj->redis_memory_used_mb = $latest->redis_memory_used_mb;
            $obj->redis_connected_clients = $latest->redis_connected_clients;
            $obj->redis_ops_per_sec = $latest->redis_ops_per_sec;
            $obj->redis_cache_hit_rate = $latest->redis_cache_hit_rate;
            $obj->cpu_load_1m = $latest->cpu_load_1m;
            $obj->memory_usage_mb = $latest->memory_usage_mb;
            $obj->disk_free_gb = $latest->disk_free_gb;
            $obj->custom = $custom;

            return $obj;
        })->values();

        return $this->buildResponse($buckets, $minutes, $timeFormat);
    }

    /**
     * Use hourly rollup table for ranges >48h.
     */
    protected function apiDataHourly(int $minutes, $since): JsonResponse
    {
        $metrics = MetricHourly::where('recorded_at', '>=', $since)
            ->orderBy('recorded_at', 'asc')
            ->get();

        if ($metrics->isEmpty()) {
            return response()->json(['status' => 'no_data']);
        }

        $timeFormat = $minutes > 1440 ? 'M d H:i' : 'H:i';

        return $this->buildResponse($metrics, $minutes, $timeFormat);
    }

    protected function aggregateCustomMetrics($metrics): array
    {
        $all = collect($metrics)->pluck('custom')->filter()->values();

        return app(MetricAggregator::class)->aggregateCustom($all);
    }

    public function apiSlowLogs(Request $request): JsonResponse
    {
        $minutes = (int) $request->input('minutes', 60);
        $minutes = min($minutes, 43200);
        $type = $request->input('type');
        $perPage = min((int) $request->input('per_page', 25), 100);
        $page = max((int) $request->input('page', 1), 1);
        $sort = $request->input('sort', 'duration');

        $query = SlowLog::where('recorded_at', '>=', now()->subMinutes($minutes));

        if ($type) {
            $query->where('type', $type);
        }

        if ($sort === 'time') {
            $query->orderBy('recorded_at', 'desc');
        } else {
            $query->orderBy('duration_ms', 'desc');
        }

        $total = $query->count();
        $lastPage = max((int) ceil($total / $perPage), 1);
        $page = min($page, $lastPage);

        $logs = $query->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->map(fn ($log) => [
                'id'          => $log->id,
                'type'        => $log->type,
                'duration_ms' => round($log->duration_ms, 1),
                'time'        => $log->recorded_at->format('H:i:s'),
                'method'      => $log->method,
                'url'         => $log->url,
                'route'       => $log->route,
                'status_code' => $log->status_code,
                'user_id'     => $log->user_id,
                'sql'         => $log->sql,
                'connection'  => $log->connection,
            ]);

        return response()->json([
            'data' => $logs,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => $lastPage,
        ]);
    }
}
