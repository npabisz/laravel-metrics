<?php

namespace Npabisz\LaravelMetrics\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Npabisz\LaravelMetrics\Models\Metric;
use Npabisz\LaravelMetrics\Models\SlowLog;
use Npabisz\LaravelMetrics\Notifications\MetricsAlertNotifiable;
use Npabisz\LaravelMetrics\Notifications\MetricsAlertNotification;

class MetricsAlertCommand extends Command
{
    protected $signature = 'monitoring:alert {--test : Send a test notification regardless of thresholds}';
    protected $description = 'Check monitoring metrics from last 15 minutes and send alert notification if thresholds are exceeded';

    public function handle(): int
    {
        if ($this->option('test')) {
            return $this->sendTestNotification();
        }

        if (!config('metrics.enabled', true)) {
            return self::SUCCESS;
        }

        $notificationsConfig = config('metrics.notifications', []);

        if (empty($notificationsConfig['enabled'])) {
            $this->info('Notifications are disabled.');
            return self::SUCCESS;
        }

        $interval = (int) ($notificationsConfig['interval'] ?? 1);
        $since = now()->subMinutes(max($interval, 1));
        $metrics = Metric::where('recorded_at', '>=', $since)->get();

        if ($metrics->isEmpty()) {
            $this->info('No metrics to evaluate.');
            return self::SUCCESS;
        }

        $latest = $metrics->sortByDesc('recorded_at')->first();

        // Build aggregated summary
        $summary = $this->buildSummary($metrics, $latest);

        // Evaluate alert rules
        $alerts = $this->evaluateAlerts($summary, $metrics);

        if (empty($alerts)) {
            $this->info('All metrics within thresholds.');
            return self::SUCCESS;
        }

        // Filter out alerts still in cooldown (per-alert or global default)
        $defaultCooldown = (int) ($notificationsConfig['cooldown'] ?? 60);
        $alerts = array_values(array_filter($alerts, function ($alert) use ($defaultCooldown) {
            $cooldown = $alert['cooldown'] ?? $defaultCooldown;

            if ($cooldown <= 0) {
                return true;
            }

            $cacheKey = 'monitoring:alert_cooldown:' . $alert['key'];

            if (Cache::has($cacheKey)) {
                $this->info("  [{$alert['key']}] skipped (cooldown active)");
                return false;
            }

            return true;
        }));

        if (empty($alerts)) {
            $this->info('All triggered alerts are in cooldown.');
            return self::SUCCESS;
        }

        $this->warn(count($alerts) . ' alert(s) triggered:');

        foreach ($alerts as $alert) {
            $this->warn("  [{$alert['severity']}] {$alert['label']}: {$alert['message']}");
        }

        // Send notification
        try {
            $notifiable = new MetricsAlertNotifiable();
            $notifiable->notify(new MetricsAlertNotification($alerts, $summary));
            $this->info('Alert notification sent.');

            // Set cooldown for each fired alert
            foreach ($alerts as $alert) {
                $cooldown = $alert['cooldown'] ?? $defaultCooldown;

                if ($cooldown > 0) {
                    Cache::put('monitoring:alert_cooldown:' . $alert['key'], true, now()->addMinutes($cooldown));
                }
            }
        } catch (\Throwable $e) {
            $this->error('Failed to send notification: ' . $e->getMessage());
        }

        return self::SUCCESS;
    }

    protected function evaluateAlerts(array $summary, $metrics): array
    {
        $alerts = [];
        $thresholds = config('metrics.notifications.thresholds', []);

        // 5xx error rate
        $errorRateThreshold = $thresholds['error_rate_5xx'] ?? 0.05;
        if ($summary['http_requests_total'] > 0) {
            $errorRate = $summary['http_requests_5xx'] / $summary['http_requests_total'];

            if ($errorRate > $errorRateThreshold) {
                $alerts[] = [
                    'key'      => 'error_rate_5xx',
                    'severity' => $errorRate > 0.15 ? 'critical' : 'warning',
                    'label'    => 'High 5xx Error Rate',
                    'message'  => round($errorRate * 100, 1) . '% (' . $summary['http_requests_5xx'] . '/' . $summary['http_requests_total'] . ' requests)',
                    'value'    => $errorRate,
                    'threshold' => $errorRateThreshold,
                ];
            }
        }

        // Queue depth
        $queueThreshold = $thresholds['queue_depth'] ?? 100;
        if ($summary['queue_depth_total'] > $queueThreshold) {
            $alerts[] = [
                'key'      => 'queue_depth',
                'severity' => $summary['queue_depth_total'] > $queueThreshold * 5 ? 'critical' : 'warning',
                'label'    => 'High Queue Depth',
                'message'  => $summary['queue_depth_total'] . " jobs pending (H:{$summary['queue_depth_high']} D:{$summary['queue_depth_default']} L:{$summary['queue_depth_low']})",
                'value'    => $summary['queue_depth_total'],
                'threshold' => $queueThreshold,
            ];
        }

        // Failed jobs
        $failedThreshold = $thresholds['queue_jobs_failed'] ?? 10;
        if ($summary['queue_jobs_failed'] > $failedThreshold) {
            $alerts[] = [
                'key'      => 'queue_jobs_failed',
                'severity' => 'warning',
                'label'    => 'Failed Jobs',
                'message'  => $summary['queue_jobs_failed'] . ' failed jobs in 15 min',
                'value'    => $summary['queue_jobs_failed'],
                'threshold' => $failedThreshold,
            ];
        }

        // Slow response time (avg across all 15min samples)
        $avgResponseThreshold = $thresholds['avg_response_ms'] ?? 2000;
        if ($summary['http_avg_duration_ms'] > $avgResponseThreshold) {
            $alerts[] = [
                'key'      => 'avg_response_time',
                'severity' => $summary['http_avg_duration_ms'] > $avgResponseThreshold * 2 ? 'critical' : 'warning',
                'label'    => 'Slow Avg Response Time',
                'message'  => $summary['http_avg_duration_ms'] . 'ms avg (threshold: ' . $avgResponseThreshold . 'ms)',
                'value'    => $summary['http_avg_duration_ms'],
                'threshold' => $avgResponseThreshold,
            ];
        }

        // Max response time
        $maxResponseThreshold = $thresholds['max_response_ms'] ?? 10000;
        if ($summary['http_max_duration_ms'] > $maxResponseThreshold) {
            $alerts[] = [
                'key'      => 'max_response_time',
                'severity' => 'warning',
                'label'    => 'Very Slow Request',
                'message'  => $summary['http_max_duration_ms'] . 'ms max (threshold: ' . $maxResponseThreshold . 'ms)',
                'value'    => $summary['http_max_duration_ms'],
                'threshold' => $maxResponseThreshold,
            ];
        }

        // CPU load
        $cpuThreshold = $thresholds['cpu_load'] ?? 5.0;
        if ($summary['cpu_load_1m'] !== null && $summary['cpu_load_1m'] > $cpuThreshold) {
            $alerts[] = [
                'key'      => 'cpu_load',
                'severity' => $summary['cpu_load_1m'] > $cpuThreshold * 2 ? 'critical' : 'warning',
                'label'    => 'High CPU Load',
                'message'  => $summary['cpu_load_1m'] . ' (threshold: ' . $cpuThreshold . ')',
                'value'    => $summary['cpu_load_1m'],
                'threshold' => $cpuThreshold,
            ];
        }

        // Disk space
        $diskThreshold = $thresholds['disk_free_gb'] ?? 2.0;
        if ($summary['disk_free_gb'] !== null && $summary['disk_free_gb'] < $diskThreshold) {
            $alerts[] = [
                'key'      => 'disk_free',
                'severity' => $summary['disk_free_gb'] < 1.0 ? 'critical' : 'warning',
                'label'    => 'Low Disk Space',
                'message'  => $summary['disk_free_gb'] . 'GB free (threshold: ' . $diskThreshold . 'GB)',
                'value'    => $summary['disk_free_gb'],
                'threshold' => $diskThreshold,
            ];
        }

        // Redis memory
        $redisThreshold = $thresholds['redis_memory_mb'] ?? 500;
        if ($summary['redis_memory_used_mb'] !== null && $summary['redis_memory_used_mb'] > $redisThreshold) {
            $alerts[] = [
                'key'      => 'redis_memory',
                'severity' => 'warning',
                'label'    => 'High Redis Memory',
                'message'  => $summary['redis_memory_used_mb'] . 'MB (threshold: ' . $redisThreshold . 'MB)',
                'value'    => $summary['redis_memory_used_mb'],
                'threshold' => $redisThreshold,
            ];
        }

        // Slow queries count
        $slowQueryThreshold = $thresholds['slow_queries'] ?? 50;
        if ($summary['db_slow_queries'] > $slowQueryThreshold) {
            $alerts[] = [
                'key'      => 'slow_queries',
                'severity' => 'warning',
                'label'    => 'Many Slow Queries',
                'message'  => $summary['db_slow_queries'] . ' slow queries in 15 min (threshold: ' . $slowQueryThreshold . ')',
                'value'    => $summary['db_slow_queries'],
                'threshold' => $slowQueryThreshold,
            ];
        }

        // Custom thresholds from config
        $customThresholds = $thresholds['custom'] ?? [];

        foreach ($customThresholds as $rule) {
            if (empty($rule['key']) || !isset($rule['threshold'])) {
                continue;
            }

            $value = $this->resolveCustomValue($rule['key'], $summary, $metrics);

            if ($value === null) {
                continue;
            }

            $operator = $rule['operator'] ?? '>';
            $triggered = match ($operator) {
                '>'  => $value > $rule['threshold'],
                '>=' => $value >= $rule['threshold'],
                '<'  => $value < $rule['threshold'],
                '<=' => $value <= $rule['threshold'],
                default => false,
            };

            if ($triggered) {
                $alert = [
                    'key'       => $rule['key'],
                    'severity'  => $rule['severity'] ?? 'warning',
                    'label'     => $rule['label'] ?? $rule['key'],
                    'message'   => $value . ' ' . $operator . ' ' . $rule['threshold'],
                    'value'     => $value,
                    'threshold' => $rule['threshold'],
                ];

                if (isset($rule['cooldown'])) {
                    $alert['cooldown'] = (int) $rule['cooldown'];
                }

                $alerts[] = $alert;
            }
        }

        return $alerts;
    }

    /**
     * Resolve a custom metric value from summary or from custom JSON column.
     */
    protected function resolveCustomValue(string $key, array $summary, $metrics)
    {
        // Check standard summary first
        if (isset($summary[$key])) {
            return $summary[$key];
        }

        // Check custom metrics from latest record
        $latest = $metrics->sortByDesc('recorded_at')->first();

        if ($latest && is_array($latest->custom) && isset($latest->custom[$key])) {
            return $latest->custom[$key];
        }

        // Aggregate custom metric across all samples (sum)
        $sum = $metrics->reduce(function ($carry, $metric) use ($key) {
            if (is_array($metric->custom) && isset($metric->custom[$key])) {
                return $carry + $metric->custom[$key];
            }
            return $carry;
        }, null);

        return $sum;
    }

    protected function buildSummary($metrics, $latest): array
    {
        return [
            'http_requests_total'  => $metrics->sum('http_requests_total'),
            'http_requests_2xx'    => $metrics->sum('http_requests_2xx'),
            'http_requests_4xx'    => $metrics->sum('http_requests_4xx'),
            'http_requests_5xx'    => $metrics->sum('http_requests_5xx'),
            'http_avg_duration_ms' => round($metrics->avg('http_avg_duration_ms'), 1),
            'http_max_duration_ms' => round($metrics->max('http_max_duration_ms'), 1),
            'http_slow_requests'   => $metrics->sum('http_slow_requests'),
            'queue_depth_high'     => $latest->queue_depth_high,
            'queue_depth_default'  => $latest->queue_depth_default,
            'queue_depth_low'      => $latest->queue_depth_low,
            'queue_depth_total'    => $latest->queue_depth_high + $latest->queue_depth_default + $latest->queue_depth_low,
            'queue_jobs_failed'    => $metrics->sum('queue_jobs_failed'),
            'db_queries_total'     => $metrics->sum('db_queries_total'),
            'db_slow_queries'      => $metrics->sum('db_slow_queries'),
            'db_max_query_ms'      => round($metrics->max('db_max_query_ms'), 1),
            'cpu_load_1m'          => $latest->cpu_load_1m,
            'redis_memory_used_mb' => $latest->redis_memory_used_mb,
            'redis_ops_per_sec'    => $latest->redis_ops_per_sec,
            'disk_free_gb'         => $latest->disk_free_gb,
        ];
    }

    protected function sendTestNotification(): int
    {
        $channels = config('metrics.notifications.channels', ['mail']);
        $this->info('Sending test notification to: ' . implode(', ', $channels));

        $alerts = [
            [
                'key'       => 'test_alert',
                'severity'  => 'critical',
                'label'     => 'Test Critical Alert',
                'message'   => 'This is a test critical alert to verify notification delivery',
                'value'     => 99,
                'threshold' => 50,
            ],
            [
                'key'       => 'test_warning',
                'severity'  => 'warning',
                'label'     => 'Test Warning Alert',
                'message'   => 'This is a test warning alert to verify notification delivery',
                'value'     => 75,
                'threshold' => 50,
            ],
        ];

        $summary = [
            'http_requests_total'  => 12345,
            'http_requests_2xx'    => 12000,
            'http_requests_4xx'    => 300,
            'http_requests_5xx'    => 45,
            'http_avg_duration_ms' => 234.5,
            'http_max_duration_ms' => 4500.0,
            'http_slow_requests'   => 12,
            'queue_depth_high'     => 5,
            'queue_depth_default'  => 150,
            'queue_depth_low'      => 42,
            'queue_depth_total'    => 197,
            'queue_jobs_failed'    => 3,
            'db_queries_total'     => 98765,
            'db_slow_queries'      => 23,
            'db_max_query_ms'      => 890.0,
            'cpu_load_1m'          => 2.4,
            'redis_memory_used_mb' => 128.5,
            'redis_ops_per_sec'    => 4500,
            'disk_free_gb'         => 15.3,
        ];

        try {
            $notifiable = new MetricsAlertNotifiable();
            $notifiable->notify(new MetricsAlertNotification($alerts, $summary));
            $this->info('Test notification sent successfully.');
        } catch (\Throwable $e) {
            $this->error('Failed to send test notification: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
