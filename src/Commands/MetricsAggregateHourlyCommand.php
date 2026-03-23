<?php

namespace Npabisz\LaravelMetrics\Commands;

use Illuminate\Console\Command;
use Npabisz\LaravelMetrics\Models\Metric;
use Npabisz\LaravelMetrics\Models\MetricHourly;
use Npabisz\LaravelMetrics\Services\MetricAggregator;

class MetricsAggregateHourlyCommand extends Command
{
    protected $signature = 'monitoring:aggregate-hourly {--hours=24 : Number of past hours to check for missing rollups}';
    protected $description = 'Aggregate per-minute metrics into hourly rollups';

    /** @var string[] Sum these columns across all samples */
    protected array $sumColumns = [
        'http_requests_total', 'http_requests_2xx', 'http_requests_4xx', 'http_requests_5xx',
        'http_slow_requests', 'queue_jobs_processed', 'queue_jobs_failed',
        'db_queries_total', 'db_slow_queries',
    ];

    /** @var string[] Use the max value from all samples */
    protected array $maxColumns = [
        'http_max_duration_ms', 'db_max_query_ms',
    ];

    /** @var array Weighted average: column => weight column */
    protected array $weightedAvgColumns = [
        'http_avg_duration_ms' => 'http_requests_total',
        'db_avg_query_ms'      => 'db_queries_total',
    ];

    /** @var string[] Use the last sample value (gauges/snapshots) */
    protected array $latestColumns = [
        'queue_depth_high', 'queue_depth_default', 'queue_depth_low',
        'redis_memory_used_mb', 'redis_connected_clients', 'redis_ops_per_sec', 'redis_cache_hit_rate',
        'cpu_load_1m', 'memory_usage_mb', 'disk_free_gb',
    ];

    public function handle(MetricAggregator $aggregator): int
    {
        $hours = (int) $this->option('hours');
        $aggregated = 0;
        $skipped = 0;

        for ($i = $hours; $i >= 1; $i--) {
            $hourStart = now()->subHours($i)->startOfHour();
            $hourEnd = $hourStart->copy()->addHour()->subSecond();

            $samples = Metric::where('recorded_at', '>=', $hourStart)
                ->where('recorded_at', '<=', $hourEnd)
                ->orderBy('recorded_at')
                ->get();

            if ($samples->isEmpty()) {
                continue;
            }

            $data = $this->buildHourlyData($hourStart, $samples, $aggregator);

            // Upsert: update if exists (idempotent), create if not
            MetricHourly::updateOrCreate(
                ['recorded_at' => $hourStart],
                $data,
            );

            $aggregated++;
        }

        $this->info("Aggregated {$aggregated} hour(s).");

        return self::SUCCESS;
    }

    protected function buildHourlyData($hourStart, $samples, MetricAggregator $aggregator): array
    {
        $data = ['samples' => $samples->count()];
        $latest = $samples->last();

        foreach ($this->sumColumns as $col) {
            $data[$col] = $samples->sum($col);
        }

        foreach ($this->maxColumns as $col) {
            $data[$col] = $samples->max($col);
        }

        foreach ($this->weightedAvgColumns as $col => $weightCol) {
            $totalWeight = 0;
            $weightedSum = 0;

            foreach ($samples as $sample) {
                $avg = (float) $sample->$col;
                $weight = (float) $sample->$weightCol;

                if ($weight > 0 && $avg > 0) {
                    $weightedSum += $avg * $weight;
                    $totalWeight += $weight;
                }
            }

            $data[$col] = $totalWeight > 0 ? round($weightedSum / $totalWeight, 1) : 0;
        }

        foreach ($this->latestColumns as $col) {
            $data[$col] = $latest->$col;
        }

        $all = $samples->pluck('custom')->filter()->values();
        $data['custom'] = $all->isNotEmpty() ? $aggregator->aggregateCustom($all) : null;

        return $data;
    }
}
