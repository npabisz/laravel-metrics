<?php

namespace Npabisz\LaravelMetrics\Commands;

use Illuminate\Console\Command;
use Npabisz\LaravelMetrics\Models\Metric;
use Npabisz\LaravelMetrics\Models\SlowLog;

class MetricsStatusCommand extends Command
{
    protected $signature = 'monitoring:status {--minutes=5 : Show data from last N minutes}';
    protected $description = 'Show current monitoring status and recent metrics';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        $since = now()->subMinutes($minutes);

        $metrics = Metric::where('recorded_at', '>=', $since)
            ->orderBy('recorded_at', 'desc')
            ->get();

        if ($metrics->isEmpty()) {
            $this->warn('No metrics found for the last ' . $minutes . ' minutes.');
            $this->info('Run "php artisan monitoring:collect" to start collecting.');
            return self::SUCCESS;
        }

        $this->info(sprintf('=== Monitoring Status (last %d minutes, %d samples) ===', $minutes, $metrics->count()));
        $this->newLine();

        // HTTP summary
        $this->info('--- HTTP ---');
        $this->table(
            ['Total Requests', 'Avg Duration (ms)', 'Max Duration (ms)', '2xx', '4xx', '5xx', 'Slow'],
            [[
                $metrics->sum('http_requests_total'),
                round($metrics->avg('http_avg_duration_ms'), 1),
                $metrics->max('http_max_duration_ms'),
                $metrics->sum('http_requests_2xx'),
                $metrics->sum('http_requests_4xx'),
                $metrics->sum('http_requests_5xx'),
                $metrics->sum('http_slow_requests'),
            ]]
        );

        // Queue summary (latest snapshot)
        $latest = $metrics->first();
        $this->info('--- Queue (current depth) ---');
        $this->table(
            ['High Priority', 'Default', 'Low Priority', 'Processed', 'Failed'],
            [[
                $latest->queue_depth_high,
                $latest->queue_depth_default,
                $latest->queue_depth_low,
                $metrics->sum('queue_jobs_processed'),
                $metrics->sum('queue_jobs_failed'),
            ]]
        );

        // Database summary
        $this->info('--- Database ---');
        $this->table(
            ['Total Queries', 'Slow Queries', 'Avg Duration (ms)', 'Max Duration (ms)'],
            [[
                $metrics->sum('db_queries_total'),
                $metrics->sum('db_slow_queries'),
                round($metrics->avg('db_avg_query_ms'), 1),
                $metrics->max('db_max_query_ms'),
            ]]
        );

        // Redis (latest)
        $this->info('--- Redis ---');
        $this->table(
            ['Memory (MB)', 'Clients', 'Ops/sec', 'Hit Rate'],
            [[
                $latest->redis_memory_used_mb ?? 'N/A',
                $latest->redis_connected_clients ?? 'N/A',
                $latest->redis_ops_per_sec ?? 'N/A',
                $latest->redis_cache_hit_rate !== null ? round($latest->redis_cache_hit_rate * 100, 1) . '%' : 'N/A',
            ]]
        );

        // System (latest)
        $this->info('--- System ---');
        $this->table(
            ['CPU Load (1m)', 'Memory (MB)', 'Disk Free (GB)'],
            [[
                $latest->cpu_load_1m ?? 'N/A',
                $latest->memory_usage_mb ?? 'N/A',
                $latest->disk_free_gb ?? 'N/A',
            ]]
        );

        // Recent slow logs
        $slowLogs = SlowLog::where('recorded_at', '>=', $since)
            ->orderBy('duration_ms', 'desc')
            ->limit(10)
            ->get();

        if ($slowLogs->isNotEmpty()) {
            $this->newLine();
            $this->info('--- Top 10 Slow Logs ---');
            $this->table(
                ['Type', 'Duration (ms)', 'Detail', 'Time'],
                $slowLogs->map(fn ($log) => [
                    $log->type,
                    round($log->duration_ms, 1),
                    $log->type === 'request'
                        ? $log->method . ' ' . mb_substr($log->url, 0, 60)
                        : mb_substr($log->sql, 0, 60),
                    $log->recorded_at->format('H:i:s'),
                ])->toArray()
            );
        }

        return self::SUCCESS;
    }
}
