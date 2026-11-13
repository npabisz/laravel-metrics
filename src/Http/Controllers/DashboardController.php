<?php

namespace Npabisz\LaravelMetrics\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Npabisz\LaravelMetrics\Models\Metric;
use Npabisz\LaravelMetrics\Models\MetricHourly;
use Npabisz\LaravelMetrics\Models\SlowLog;
use Npabisz\LaravelMetrics\Services\MetricAggregator;

class DashboardController extends Controller
{
    protected const MAX_MINUTES = 43200; // 30 days

    protected const DEFAULT_PERIODS = [15 => '15m', 60 => '1h', 360 => '6h', 1440 => '24h'];

    protected const DEFAULT_STATUS_RULES = [
        ['label' => 'Error rate', 'value' => 'error_rate', 'warn' => '> 1', 'error' => '> 5'],
        ['label' => 'Queue depth', 'value' => 'queue_depth_default', 'warn' => '> 100'],
    ];

    public function index()
    {
        $dashboard = config('metrics.dashboard', []);
        $periods = $this->periods($dashboard['periods'] ?? null);
        $defaultPeriod = (int) ($dashboard['default_period'] ?? 60);

        if (!in_array($defaultPeriod, array_column($periods, 'minutes'), true)) {
            $defaultPeriod = $periods[0]['minutes'];
        }

        return view('metrics::dashboard', [
            'basePath' => trim((string) ($dashboard['path'] ?? 'metrics'), '/'),
            'settings' => [
                'views'         => $dashboard['views'] ?? [],
                'periods'       => $periods,
                'defaultPeriod' => $defaultPeriod,
                'refresh'       => max((int) ($dashboard['refresh'] ?? 30), 0),
                'status'        => $dashboard['status'] ?? self::DEFAULT_STATUS_RULES,
                'names'         => $dashboard['names'] ?? [],
            ],
        ]);
    }

    /**
     * Normalize the configured period map (minutes => label) into an ordered list.
     *
     * @return array<int, array{minutes: int, label: string}>
     */
    protected function periods(?array $configured): array
    {
        $periods = [];

        foreach ($configured ?: self::DEFAULT_PERIODS as $minutes => $label) {
            $minutes = (int) $minutes;

            if ($minutes < 1 || $minutes > self::MAX_MINUTES) {
                continue;
            }

            $periods[] = ['minutes' => $minutes, 'label' => (string) $label];
        }

        if ($periods === []) {
            return $this->periods(self::DEFAULT_PERIODS);
        }

        usort($periods, fn ($a, $b) => $a['minutes'] <=> $b['minutes']);

        return $periods;
    }

    public function apiData(Request $request): JsonResponse
    {
        $minutes = $this->minutes($request);
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
    protected function buildResponse($metrics, int $minutes, string $timeFormat, int $bucketSeconds = 60): JsonResponse
    {
        $latest = $metrics->last();
        $totalRequests = $metrics->sum('http_requests_total');
        $total5xx = $metrics->sum('http_requests_5xx');

        return response()->json([
            'status'  => 'ok',
            'period'  => $minutes,
            'samples' => $metrics->count(),
            'bucket'  => $bucketSeconds, // seconds per timeline row
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
                    'ts'               => $m->recorded_at->getTimestamp(),
                    'requests'         => $m->http_requests_total,
                    'avg_duration'     => round($m->http_avg_duration_ms, 1),
                    'max_duration'     => round($m->http_max_duration_ms, 1),
                    'errors_5xx'       => $m->http_requests_5xx,
                    'http_slow'        => $m->http_slow_requests,
                    'queue_high'       => $m->queue_depth_high,
                    'queue_default'    => $m->queue_depth_default,
                    'queue_low'        => $m->queue_depth_low,
                    'db_queries'       => $m->db_queries_total,
                    'db_slow'          => $m->db_slow_queries,
                    'cpu'              => $m->cpu_load_1m,
                    'redis_memory'     => $m->redis_memory_used_mb,
                    'redis_ops'        => $m->redis_ops_per_sec,
                ];

                // Strings are kept too, so label metrics (e.g. top-N names)
                // can be paired with their counts minute by minute.
                if (is_array($m->custom)) {
                    foreach ($m->custom as $key => $value) {
                        if (is_numeric($value) || is_string($value)) {
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

        return $this->buildResponse($buckets, $minutes, $timeFormat, $bucketMinutes * 60);
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

        return $this->buildResponse($metrics, $minutes, $timeFormat, 3600);
    }

    protected function aggregateCustomMetrics($metrics): array
    {
        $all = collect($metrics)->pluck('custom')->filter()->values();

        return app(MetricAggregator::class)->aggregateCustom($all);
    }

    public function apiSlowLogs(Request $request): JsonResponse
    {
        [$since, $until] = $this->slowLogWindow($request);
        $type = $request->input('type');
        $route = $request->input('route');
        $perPage = max(min((int) $request->input('per_page', 25), 100), 1);
        $page = max((int) $request->input('page', 1), 1);
        $sort = $request->input('sort', 'duration');

        $query = SlowLog::where('recorded_at', '>=', $since);

        if ($until !== null) {
            $query->where('recorded_at', '<=', $until);
        }

        if (is_string($type) && $type !== '') {
            $query->where('type', $type);
        }

        if (is_string($route) && $route !== '') {
            // Explicit ESCAPE: SQLite has no default escape character.
            $column = $query->getQuery()->getGrammar()->wrap('route');
            $query->whereRaw("{$column} like ? escape '!'", [$this->likePattern($route)]);
        }

        // Count before ordering: PostgreSQL rejects ORDER BY next to an aggregate.
        $total = $query->count();
        $lastPage = max((int) ceil($total / $perPage), 1);
        $page = min($page, $lastPage);

        if ($sort === 'time') {
            $query->orderBy('recorded_at', 'desc');
        } else {
            $query->orderBy('duration_ms', 'desc');
        }

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

    protected function minutes(Request $request): int
    {
        return max(min((int) $request->input('minutes', 60), self::MAX_MINUTES), 1);
    }

    /**
     * Time window for slow logs: 'from'/'to' (unix seconds, e.g. around a
     * pinned chart point) when both are given, otherwise the last N minutes.
     *
     * @return array{0: Carbon, 1: Carbon|null}
     */
    protected function slowLogWindow(Request $request): array
    {
        $from = $request->input('from');
        $to = $request->input('to');
        $earliest = now()->subMinutes(self::MAX_MINUTES);

        if (is_numeric($from) && is_numeric($to) && (int) $from <= (int) $to) {
            // Explicit zone: Carbon 3 creates timestamps in UTC, while
            // recorded_at is stored as app-timezone wall-clock time.
            $zone = config('app.timezone');
            $since = Carbon::createFromTimestamp((int) $from, $zone);

            return [$since->lt($earliest) ? $earliest : $since, Carbon::createFromTimestamp((int) $to, $zone)];
        }

        return [now()->subMinutes($this->minutes($request)), null];
    }

    /**
     * Turn a '*' wildcard pattern into a LIKE pattern escaped with '!'.
     */
    protected function likePattern(string $pattern): string
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $pattern);

        return str_replace('*', '%', $escaped);
    }
}
