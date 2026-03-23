<?php

namespace Npabisz\LaravelMetrics\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Npabisz\LaravelMetrics\Models\Metric;
use Npabisz\LaravelMetrics\Models\SlowLog;

class MetricsController extends Controller
{
    public function metrics(Request $request): JsonResponse
    {
        $minutes = (int) $request->input('minutes', 60);
        $minutes = min($minutes, 1440); // max 24h

        $metrics = Metric::where('recorded_at', '>=', now()->subMinutes($minutes))
            ->orderBy('recorded_at', 'desc')
            ->get();

        return response()->json([
            'period_minutes' => $minutes,
            'samples'        => $metrics->count(),
            'data'           => $metrics,
        ]);
    }

    public function slowLogs(Request $request): JsonResponse
    {
        $minutes = (int) $request->input('minutes', 60);
        $minutes = min($minutes, 1440);
        $type = $request->input('type'); // 'query' or 'request'
        $limit = min((int) $request->input('limit', 50), 200);

        $query = SlowLog::where('recorded_at', '>=', now()->subMinutes($minutes))
            ->orderBy('duration_ms', 'desc')
            ->limit($limit);

        if ($type) {
            $query->where('type', $type);
        }

        return response()->json([
            'period_minutes' => $minutes,
            'type'           => $type,
            'data'           => $query->get(),
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $minutes = (int) $request->input('minutes', 5);
        $since = now()->subMinutes($minutes);

        $metrics = Metric::where('recorded_at', '>=', $since)->get();
        $latest = $metrics->sortByDesc('recorded_at')->first();

        if (!$latest) {
            return response()->json(['status' => 'no_data', 'message' => 'No metrics collected yet']);
        }

        $slowQueries = SlowLog::where('recorded_at', '>=', $since)
            ->where('type', 'query')
            ->count();

        $slowRequests = SlowLog::where('recorded_at', '>=', $since)
            ->where('type', 'request')
            ->count();

        return response()->json([
            'status'         => 'ok',
            'period_minutes' => $minutes,
            'samples'        => $metrics->count(),
            'http'           => [
                'requests_total' => $metrics->sum('http_requests_total'),
                'avg_duration'   => round($metrics->avg('http_avg_duration_ms'), 1),
                'max_duration'   => $metrics->max('http_max_duration_ms'),
                'errors_5xx'     => $metrics->sum('http_requests_5xx'),
                'slow_requests'  => $slowRequests,
            ],
            'queue'          => [
                'depth_high'    => $latest->queue_depth_high,
                'depth_default' => $latest->queue_depth_default,
                'depth_low'     => $latest->queue_depth_low,
                'processed'     => $metrics->sum('queue_jobs_processed'),
                'failed'        => $metrics->sum('queue_jobs_failed'),
            ],
            'database'       => [
                'queries_total' => $metrics->sum('db_queries_total'),
                'slow_queries'  => $slowQueries,
                'avg_query_ms'  => round($metrics->avg('db_avg_query_ms'), 1),
                'max_query_ms'  => $metrics->max('db_max_query_ms'),
            ],
            'redis'          => [
                'memory_mb'  => $latest->redis_memory_used_mb,
                'clients'    => $latest->redis_connected_clients,
                'ops_per_sec' => $latest->redis_ops_per_sec,
                'hit_rate'   => $latest->redis_cache_hit_rate,
            ],
            'system'         => [
                'cpu_load' => $latest->cpu_load_1m,
                'memory_mb' => $latest->memory_usage_mb,
                'disk_free_gb' => $latest->disk_free_gb,
            ],
            'custom'         => $latest->custom,
        ]);
    }
}
