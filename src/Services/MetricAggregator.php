<?php

namespace Npabisz\LaravelMetrics\Services;

class MetricAggregator
{
    protected array $configRules;
    protected string $gaugePattern;
    protected array $weightSuffixes = ['_calls', '_requests', '_count'];

    protected static array $defaultGaugePatterns = [
        '_avg_', 'avg_ms', '_max_', 'max_ms', 'p95_', 'p99_', 'hit_rate',
        '_status', '_load', '_count', '_size_mb', '_total_mb', '_free_gb',
        '_memory_mb', '_limit_mb', '_clients', '_ops_per_sec', 'active_',
        '_percent', '_mbps', '_domain',
    ];

    public function __construct()
    {
        $this->configRules = config('metrics.aggregation', []);
        $this->gaugePattern = $this->buildGaugePattern();
    }

    /**
     * Determine the aggregation strategy for a given key.
     *
     * @return array{strategy: string, weight_key: string|null}
     */
    public function resolveStrategy(string $key, $allKeys = null): array
    {
        // Check config rules first
        foreach ($this->configRules as $rule) {
            if (empty($rule['pattern'])) {
                continue;
            }

            if ($this->matchesPattern($key, $rule['pattern'])) {
                return [
                    'strategy' => $rule['strategy'] ?? 'sum',
                    'weight_key' => $rule['weight_key'] ?? null,
                ];
            }
        }

        // Auto-detect: *_avg_* → weighted avg
        if (preg_match('/^(.+)_avg_(.+)$/', $key, $m)) {
            $weightKey = $allKeys ? $this->findWeightKey($m[1], $allKeys) : null;

            return ['strategy' => 'avg', 'weight_key' => $weightKey];
        }

        // Auto-detect: *_max_*, *_p95_*, *_p99_* → max
        if (str_contains($key, '_max_') || str_contains($key, '_p95_') || str_contains($key, '_p99_')) {
            return ['strategy' => 'max', 'weight_key' => null];
        }

        // Auto-detect: gauge patterns → latest
        if (preg_match($this->gaugePattern, $key)) {
            return ['strategy' => 'latest', 'weight_key' => null];
        }

        // Default: sum
        return ['strategy' => 'sum', 'weight_key' => null];
    }

    /**
     * Aggregate custom metrics from a collection of samples.
     */
    public function aggregateCustom($all): array
    {
        if ($all->isEmpty()) {
            return [];
        }

        $allKeys = $all->flatMap(fn ($c) => array_keys($c))->unique()->values();
        $latest = $all->last();
        $result = [];

        foreach ($allKeys as $key) {
            $latestValue = $latest[$key] ?? null;

            // Non-numeric: always use latest
            if ($latestValue !== null && !is_numeric($latestValue)) {
                $result[$key] = $latestValue;
                continue;
            }

            $info = $this->resolveStrategy($key, $allKeys);

            $result[$key] = match ($info['strategy']) {
                'avg' => $info['weight_key']
                    ? $this->weightedAverage($all, $key, $info['weight_key'])
                    : ($latestValue ?? 0),
                'max' => $all->max(fn ($c) => is_numeric($c[$key] ?? null) ? $c[$key] : null),
                'latest' => $latestValue,
                default => $all->sum(fn ($c) => is_numeric($c[$key] ?? null) ? $c[$key] : 0),
            };
        }

        return $result;
    }

    /**
     * Compute weighted average across samples.
     */
    public function weightedAverage($all, string $avgKey, string $weightKey): float
    {
        $totalWeight = 0;
        $weightedSum = 0;

        foreach ($all as $sample) {
            $avg = is_numeric($sample[$avgKey] ?? null) ? (float) $sample[$avgKey] : 0;
            $weight = is_numeric($sample[$weightKey] ?? null) ? (float) $sample[$weightKey] : 0;

            if ($weight > 0 && $avg > 0) {
                $weightedSum += $avg * $weight;
                $totalWeight += $weight;
            }
        }

        return $totalWeight > 0 ? round($weightedSum / $totalWeight, 1) : 0;
    }

    public function findWeightKey(string $prefix, $allKeys): ?string
    {
        foreach ($this->weightSuffixes as $suffix) {
            $candidate = $prefix . $suffix;

            if ($allKeys->contains($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function getGaugePattern(): string
    {
        return $this->gaugePattern;
    }

    protected function buildGaugePattern(): string
    {
        $escaped = array_map(fn ($p) => preg_quote($p, '/'), static::$defaultGaugePatterns);

        return '/(' . implode('|', $escaped) . ')/';
    }

    protected function matchesPattern(string $key, string $pattern): bool
    {
        if (!str_contains($pattern, '*')) {
            return $key === $pattern;
        }

        $regex = '/^' . str_replace('\\*', '.*', preg_quote($pattern, '/')) . '$/';

        return (bool) preg_match($regex, $key);
    }
}
