<?php

namespace Npabisz\LaravelMonitoring\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Npabisz\LaravelMonitoring\Models\MonitoringMetric;
use Npabisz\LaravelMonitoring\Models\MonitoringSlowLog;

class DashboardController extends Controller
{
    public function index()
    {
        return view('monitoring::dashboard', [
            'customCharts' => config('monitoring.dashboard.custom_charts', []),
        ]);
    }

    public function apiData(Request $request): JsonResponse
    {
        $minutes = (int) $request->input('minutes', 60);
        $minutes = min($minutes, 1440);
        $since = now()->subMinutes($minutes);

        $metrics = MonitoringMetric::where('recorded_at', '>=', $since)
            ->orderBy('recorded_at', 'asc')
            ->get();

        $latest = $metrics->last();

        if (!$latest) {
            return response()->json(['status' => 'no_data']);
        }

        // Aggregate for summary cards
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
            'timeline' => $metrics->map(function ($m) {
                $row = [
                    'time'             => $m->recorded_at->format('H:i'),
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

                // Include numeric custom metrics in timeline
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
     * Aggregate custom metrics across all samples.
     * Keys ending in _avg_ms, _hit_rate, _status, _load are averaged/taken from latest.
     * Everything else is summed.
     */
    protected function aggregateCustomMetrics($metrics): array
    {
        $all = $metrics->pluck('custom')->filter()->values();

        if ($all->isEmpty()) {
            return [];
        }

        $allKeys = $all->flatMap(fn ($c) => array_keys($c))->unique()->values();
        $latest = $all->last();
        $result = [];

        foreach ($allKeys as $key) {
            // Snapshot metrics — use latest value
            if (preg_match('/(avg_ms|max_ms|p95_|hit_rate|_status|_load|_count|_size_mb|_free_gb|_memory_mb|_clients|_ops_per_sec|active_)/', $key)) {
                $result[$key] = $latest[$key] ?? null;
            } else {
                // Counter metrics — sum across all samples
                $result[$key] = $all->sum(fn ($c) => $c[$key] ?? 0);
            }
        }

        return $result;
    }

    public function apiSlowLogs(Request $request): JsonResponse
    {
        $minutes = (int) $request->input('minutes', 60);
        $minutes = min($minutes, 1440);
        $type = $request->input('type');
        $perPage = min((int) $request->input('per_page', 25), 100);
        $page = max((int) $request->input('page', 1), 1);
        $sort = $request->input('sort', 'duration');

        $query = MonitoringSlowLog::where('recorded_at', '>=', now()->subMinutes($minutes));

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
