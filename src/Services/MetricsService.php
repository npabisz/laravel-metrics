<?php

namespace Npabisz\LaravelMetrics\Services;

use Illuminate\Support\Facades\Redis;
use Npabisz\LaravelMetrics\Collectors\CollectorInterface;
use Npabisz\LaravelMetrics\Models\Metric;
use Npabisz\LaravelMetrics\Models\SlowLog;

class MetricsService
{
    /**
     * Seconds a counter, list or key index lives in Redis after it is
     * created, should monitoring:collect not take it before.
     */
    protected const KEY_TTL = 120;

    protected bool $enabled;
    protected ?string $redisConnection = null;
    protected ?string $redisPrefix = null;
    protected bool $batch;
    protected int $batchSize;

    /**
     * What increment() and pushToList() recorded since the last flush().
     *
     * @var array<string, int> Redis key => amount
     */
    protected array $pendingCounters = [];

    /** @var array<string, array> Redis key => values, in order */
    protected array $pendingLists = [];

    /** @var array<string, array<string, true>> group => keys to register in its index */
    protected array $pendingKeys = [];

    protected int $pendingCount = 0;

    /** @var callable|null */
    protected static $alertCallback = null;

    public function __construct()
    {
        $this->enabled = (bool) config('metrics.enabled', true);
        $this->redisConnection = config('metrics.redis_connection', 'default');
        $this->redisPrefix = config('metrics.redis_prefix', 'monitoring:');
        $this->batch = (bool) config('metrics.batch.enabled', true);
        $this->batchSize = max(1, (int) config('metrics.batch.size', 500));
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Register a callback for alert notifications.
     *
     * Callback signature: function(string $alertName, mixed $value, mixed $threshold): void
     */
    public static function onAlert(callable $callback): void
    {
        static::$alertCallback = $callback;
    }

    /**
     * Increment a Redis counter for the current collection period.
     */
    public function increment(string $group, string $key, int $amount = 1): void
    {
        if (!$this->enabled) return;

        if ($this->batch) {
            $redisKey = $this->redisPrefix . $group . ':' . $key;
            $this->pendingCounters[$redisKey] = ($this->pendingCounters[$redisKey] ?? 0) + $amount;
            $this->pendingKeys[$group][$key] = true;
            $this->recorded();

            return;
        }

        try {
            $redisKey = $this->redisPrefix . $group . ':' . $key;
            $redis = Redis::connection($this->redisConnection);
            $newValue = $redis->incrby($redisKey, $amount);

            if ($newValue == $amount) {
                $redis->expire($redisKey, 120);
                // Register key in the group's key index
                $this->registerKey($redis, $group, $key);
            }
        } catch (\Throwable $e) {
            // Silently fail — monitoring should not break the app
        }
    }

    /**
     * Append a value to a Redis list (e.g. request durations).
     */
    public function pushToList(string $group, string $key, $value): void
    {
        if (!$this->enabled) return;

        if ($this->batch) {
            $this->pendingLists[$this->redisPrefix . $group . ':' . $key][] = $value;
            $this->pendingKeys[$group][$key] = true;
            $this->recorded();

            return;
        }

        try {
            $redisKey = $this->redisPrefix . $group . ':' . $key;
            $redis = Redis::connection($this->redisConnection);
            $length = $redis->rpush($redisKey, [$value]);

            if ($length == 1) {
                $redis->expire($redisKey, 120);
                // Register key in the group's key index
                $this->registerKey($redis, $group, $key);
            }
        } catch (\Throwable $e) {
            // Silently fail
        }
    }

    /**
     * Send everything increment() and pushToList() recorded since the last
     * call to Redis, in one round trip.
     *
     * A key gets its TTL when it is created, not on every write, so that a
     * list nobody collects stops growing. Which keys this flush created is
     * known only from the replies, so their TTL goes out in a second round
     * trip - in practice once per collection interval, by the first flush
     * after monitoring:collect took the previous keys.
     *
     * Called after the response is sent, after each queue job and after each
     * console command (see MetricsServiceProvider), and on its own once
     * 'batch.size' values are waiting.
     */
    public function flush(): void
    {
        if ($this->pendingCount === 0) return;

        $commands = [];

        foreach ($this->pendingCounters as $redisKey => $amount) {
            $commands[] = ['incrby', $redisKey, $amount, fn ($reply) => (int) $reply === $amount];
        }

        foreach ($this->pendingLists as $redisKey => $values) {
            $commands[] = ['rpush', $redisKey, $values, fn ($reply) => (int) $reply === count($values)];
        }

        foreach ($this->pendingKeys as $group => $keys) {
            // A new member means the index was taken by monitoring:collect
            // (or has expired) and has just been created again.
            $commands[] = ['sadd', $this->redisPrefix . '_keys:' . $group, array_keys($keys), fn ($reply) => (int) $reply > 0];
        }

        $this->pendingCounters = [];
        $this->pendingLists = [];
        $this->pendingKeys = [];
        $this->pendingCount = 0;

        try {
            $redis = Redis::connection($this->redisConnection);

            $replies = $redis->pipeline(function ($pipe) use ($commands) {
                foreach ($commands as [$command, $redisKey, $argument]) {
                    $this->pipe($pipe, $command, $redisKey, $argument);
                }
            });

            $created = [];

            foreach ($commands as $index => [, $redisKey, , $wasCreated]) {
                $reply = $replies[$index] ?? null;

                if (is_numeric($reply) && $wasCreated($reply)) {
                    $created[] = $redisKey;
                }
            }

            if ($created) {
                $redis->pipeline(function ($pipe) use ($created) {
                    foreach ($created as $redisKey) {
                        $pipe->expire($redisKey, static::KEY_TTL);
                    }
                });
            }
        } catch (\Throwable $e) {
            // Silently fail — monitoring should not break the app
        }
    }

    /**
     * Number of values recorded and not yet sent to Redis.
     */
    public function pendingCount(): int
    {
        return $this->pendingCount;
    }

    protected function recorded(): void
    {
        if (++$this->pendingCount >= $this->batchSize) {
            $this->flush();
        }
    }

    /**
     * Queue a command taking several values on a pipeline. Predis takes them
     * as an array; phpredis hands Laravel's pipeline its own client, which
     * takes them as separate arguments.
     */
    protected function pipe($pipe, string $command, string $redisKey, $argument): void
    {
        if (is_array($argument) && $pipe instanceof \Redis) {
            $pipe->{$command}($redisKey, ...$argument);

            return;
        }

        $pipe->{$command}($redisKey, $argument);
    }

    /**
     * Register a key name in the group's index SET.
     */
    protected function registerKey($redis, string $group, string $key): void
    {
        $indexKey = $this->redisPrefix . '_keys:' . $group;
        $redis->sadd($indexKey, [$key]);
        $redis->expire($indexKey, 120);
    }

    /**
     * Decode a JSON-encoded durations list from flushed Redis data.
     */
    public static function decodeDurations(array $data, string $key): array
    {
        return json_decode($data[$key] ?? '[]', true) ?: [];
    }

    /**
     * Calculate average from a durations array.
     */
    public static function avgDuration(array $durations): float
    {
        return count($durations) > 0 ? round(array_sum($durations) / count($durations), 1) : 0;
    }

    /**
     * Calculate max from a durations array.
     */
    public static function maxDuration(array $durations): float
    {
        return count($durations) > 0 ? round(max($durations), 1) : 0;
    }

    /**
     * Calculate percentile from a durations array.
     */
    public static function percentile(array $values, int $p): float
    {
        if (empty($values)) {
            return 0;
        }

        sort($values);
        $index = ceil(count($values) * $p / 100) - 1;

        return round($values[max(0, $index)], 1);
    }

    /**
     * Flush and return all Redis counters for a group.
     * Returns key => value pairs, with lists returned as JSON arrays.
     *
     * Uses a key index SET (populated by increment/pushToList) instead of SCAN
     * to avoid Redis prefix compatibility issues across Predis/phpredis.
     */
    public function flushRedisCounters(string $group): array
    {
        $data = [];

        // What this process recorded counts too.
        $this->flush();

        try {
            $redis = Redis::connection($this->redisConnection);

            // Read and delete the key index for this group
            $indexKey = $this->redisPrefix . '_keys:' . $group;
            $keys = $redis->smembers($indexKey);
            $redis->del($indexKey);

            if (empty($keys)) {
                return $data;
            }

            foreach ($keys as $shortKey) {
                $redisKey = $this->redisPrefix . $group . ':' . $shortKey;
                $type = (string) $redis->type($redisKey);

                // Predis returns Status object (cast to 'list'), phpredis returns int (3)
                if ($type === 'list' || $type === '3') {
                    $values = $redis->lrange($redisKey, 0, -1);
                    $data[$shortKey] = json_encode(array_map('floatval', $values));
                } else {
                    $data[$shortKey] = $redis->get($redisKey);
                }

                $redis->del($redisKey);
            }
        } catch (\Throwable $e) {
            // Silently fail
        }

        return $data;
    }

    /**
     * Run all configured collectors and store metrics.
     */
    public function collectAndStore(): Metric
    {
        $metrics = ['recorded_at' => now()];
        $custom = [];

        $collectors = config('metrics.collectors', []);

        foreach ($collectors as $collectorClass) {
            try {
                /** @var CollectorInterface $collector */
                $collector = app($collectorClass);
                $collected = $collector->collect();

                // Separate custom metrics from standard ones
                if (isset($collected['custom'])) {
                    $custom = array_merge($custom, $collected['custom']);
                    unset($collected['custom']);
                }

                $metrics = array_merge($metrics, $collected);
            } catch (\Throwable $e) {
                // Individual collector failure should not stop others
            }
        }

        if (!empty($custom)) {
            $metrics['custom'] = $custom;
        }

        $record = Metric::create($metrics);

        $this->checkAlerts($metrics);

        return $record;
    }

    /**
     * Log a slow query to the database.
     */
    public function logSlowQuery(
        string $sql,
        array  $bindings,
        float  $durationMs,
        string $connection,
        ?array $context = null
    ): void {
        if (!$this->enabled) return;

        try {
            SlowLog::create([
                'recorded_at' => now(),
                'type'        => SlowLog::TYPE_QUERY,
                'sql'         => mb_substr($sql, 0, 65535),
                'bindings'    => $bindings,
                'duration_ms' => round($durationMs, 2),
                'connection'  => $connection,
                'context'     => $context,
            ]);
        } catch (\Throwable $e) {
            // Silently fail
        }
    }

    /**
     * Log a slow request to the database.
     */
    public function logSlowRequest(
        string  $method,
        string  $url,
        ?string $route,
        int     $statusCode,
        float   $durationMs,
        ?int    $userId = null,
        ?array  $context = null
    ): void {
        if (!$this->enabled) return;

        try {
            SlowLog::create([
                'recorded_at' => now(),
                'type'        => SlowLog::TYPE_REQUEST,
                'method'      => $method,
                'url'         => mb_substr($url, 0, 500),
                'route'       => $route ? mb_substr($route, 0, 200) : null,
                'status_code' => $statusCode,
                'duration_ms' => round($durationMs, 2),
                'user_id'     => $userId,
                'context'     => $context,
            ]);
        } catch (\Throwable $e) {
            // Silently fail
        }
    }

    /**
     * Clean up old monitoring data.
     */
    public function cleanup(): array
    {
        $retentionMetrics = config('metrics.retention.metrics', 30);
        $retentionSlowLogs = config('metrics.retention.slow_logs', 14);

        $deletedMetrics = Metric::where('recorded_at', '<', now()->subDays($retentionMetrics))->delete();
        $deletedSlowLogs = SlowLog::where('recorded_at', '<', now()->subDays($retentionSlowLogs))->delete();

        return [
            'metrics'   => $deletedMetrics,
            'slow_logs' => $deletedSlowLogs,
        ];
    }

    /**
     * Check metrics against configured alert thresholds.
     */
    protected function checkAlerts(array $metrics): void
    {
        if (!static::$alertCallback) {
            return;
        }

        $alerts = config('metrics.alerts', []);
        $mapping = [
            'queue_depth_high'      => 'queue_depth_high',
            'queue_depth_default'   => 'queue_depth_default',
            'cpu_load'              => 'cpu_load_1m',
            'redis_memory_mb'       => 'redis_memory_used_mb',
        ];

        foreach ($mapping as $alertKey => $metricKey) {
            if (!isset($alerts[$alertKey], $metrics[$metricKey])) {
                continue;
            }

            if ($metrics[$metricKey] > $alerts[$alertKey]) {
                call_user_func(static::$alertCallback, $alertKey, $metrics[$metricKey], $alerts[$alertKey]);
            }
        }

        // Disk free — alert when BELOW threshold
        if (isset($alerts['disk_free_gb'], $metrics['disk_free_gb'])) {
            if ($metrics['disk_free_gb'] < $alerts['disk_free_gb']) {
                call_user_func(static::$alertCallback, 'disk_free_gb', $metrics['disk_free_gb'], $alerts['disk_free_gb']);
            }
        }

        // 5xx error rate
        if (isset($alerts['http_error_rate_5xx']) && ($metrics['http_requests_total'] ?? 0) > 0) {
            $rate = ($metrics['http_requests_5xx'] ?? 0) / $metrics['http_requests_total'];

            if ($rate > $alerts['http_error_rate_5xx']) {
                call_user_func(static::$alertCallback, 'http_error_rate_5xx', $rate, $alerts['http_error_rate_5xx']);
            }
        }
    }
}
