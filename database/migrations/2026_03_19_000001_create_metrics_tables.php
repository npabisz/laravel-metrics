<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection()
    {
        return config('metrics.connection');
    }

    public function up()
    {
        Schema::connection($this->getConnection())->create('metrics', function (Blueprint $table) {
            $table->id();
            $table->timestamp('recorded_at');

            // HTTP
            $table->unsignedInteger('http_requests_total')->default(0);
            $table->unsignedInteger('http_requests_2xx')->default(0);
            $table->unsignedInteger('http_requests_4xx')->default(0);
            $table->unsignedInteger('http_requests_5xx')->default(0);
            $table->float('http_avg_duration_ms')->default(0);
            $table->float('http_max_duration_ms')->default(0);
            $table->unsignedInteger('http_slow_requests')->default(0);

            // Queue
            $table->unsignedInteger('queue_depth_high')->default(0);
            $table->unsignedInteger('queue_depth_default')->default(0);
            $table->unsignedInteger('queue_depth_low')->default(0);
            $table->unsignedInteger('queue_jobs_processed')->default(0);
            $table->unsignedInteger('queue_jobs_failed')->default(0);

            // Database
            $table->unsignedInteger('db_queries_total')->default(0);
            $table->unsignedInteger('db_slow_queries')->default(0);
            $table->float('db_avg_query_ms')->default(0);
            $table->float('db_max_query_ms')->default(0);

            // Redis
            $table->float('redis_memory_used_mb')->nullable();
            $table->unsignedInteger('redis_connected_clients')->nullable();
            $table->unsignedInteger('redis_ops_per_sec')->nullable();
            $table->float('redis_cache_hit_rate')->nullable();

            // System
            $table->float('cpu_load_1m')->nullable();
            $table->float('memory_usage_mb')->nullable();
            $table->float('disk_free_gb')->nullable();

            // Custom metrics (extensible JSON)
            $table->json('custom')->nullable();

            $table->index('recorded_at', 'idx_metrics_recorded_at');
        });

        Schema::connection($this->getConnection())->create('metrics_slow_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('recorded_at');

            // Type: 'query' or 'request'
            $table->string('type', 10);

            // For requests
            $table->string('method', 10)->nullable();
            $table->string('url', 500)->nullable();
            $table->string('route', 200)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();

            // For queries
            $table->text('sql')->nullable();
            $table->json('bindings')->nullable();
            $table->string('connection', 50)->nullable();

            // Shared
            $table->float('duration_ms');
            $table->json('context')->nullable();

            $table->index('recorded_at', 'idx_slow_recorded_at');
            $table->index(['type', 'recorded_at'], 'idx_slow_type_date');
            $table->index('user_id', 'idx_slow_user');
        });
    }

    public function down()
    {
        $connection = $this->getConnection();
        Schema::connection($connection)->dropIfExists('metrics_slow_logs');
        Schema::connection($connection)->dropIfExists('metrics');
    }
};
