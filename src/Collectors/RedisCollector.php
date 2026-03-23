<?php

namespace Npabisz\LaravelMetrics\Collectors;

use Illuminate\Support\Facades\Redis;

class RedisCollector implements CollectorInterface
{
    public function collect(): array
    {
        try {
            $connection = config('metrics.redis_connection', 'default');
            $info = Redis::connection($connection)->info();

            // Handle different Redis client response formats
            $server = $info['Server'] ?? $info;
            $memory = $info['Memory'] ?? $info;
            $stats = $info['Stats'] ?? $info;
            $clients = $info['Clients'] ?? $info;

            $hits = (int) ($stats['keyspace_hits'] ?? 0);
            $misses = (int) ($stats['keyspace_misses'] ?? 0);
            $hitRate = ($hits + $misses) > 0 ? $hits / ($hits + $misses) : null;

            return [
                'redis_memory_used_mb'    => round(((int) ($memory['used_memory'] ?? 0)) / 1048576, 2),
                'redis_connected_clients' => (int) ($clients['connected_clients'] ?? 0),
                'redis_ops_per_sec'       => (int) ($stats['instantaneous_ops_per_sec'] ?? 0),
                'redis_cache_hit_rate'    => $hitRate !== null ? round($hitRate, 4) : null,
            ];
        } catch (\Throwable $e) {
            return [
                'redis_memory_used_mb'    => null,
                'redis_connected_clients' => null,
                'redis_ops_per_sec'       => null,
                'redis_cache_hit_rate'    => null,
            ];
        }
    }
}
