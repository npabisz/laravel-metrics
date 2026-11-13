<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Enable/Disable Monitoring
    |--------------------------------------------------------------------------
    */
    'enabled' => env('METRICS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    | Which database connection to use for monitoring tables.
    | Set to null to use the default connection.
    */
    'connection' => env('METRICS_DB_CONNECTION', null),

    /*
    |--------------------------------------------------------------------------
    | Slow Request Thresholds (ms)
    |--------------------------------------------------------------------------
    | Requests exceeding these thresholds will be logged to slow_logs.
    | Define route pattern => threshold. First match wins.
    | '*' is the default fallback.
    */
    'slow_request_thresholds' => [
        '*' => 2000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Slow Query Threshold (ms)
    |--------------------------------------------------------------------------
    */
    'slow_query_threshold' => env('METRICS_SLOW_QUERY_MS', 100),

    /*
    |--------------------------------------------------------------------------
    | Query Listener
    |--------------------------------------------------------------------------
    | Track all DB queries for aggregate metrics.
    | Set to false on high-traffic apps if overhead is a concern.
    */
    'track_queries' => env('METRICS_TRACK_QUERIES', true),

    /*
    |--------------------------------------------------------------------------
    | Request Tracking
    |--------------------------------------------------------------------------
    | Auto-register the RequestMonitor middleware on all routes.
    | Set to false if you want to apply it manually to specific route groups.
    */
    'track_requests' => env('METRICS_TRACK_REQUESTS', true),

    /*
    |--------------------------------------------------------------------------
    | Request Trace
    |--------------------------------------------------------------------------
    | While a request runs, what it spends its time on is kept in memory:
    | database queries (count, total time, the costliest statements) and
    | whatever the application measures with RequestTrace (measure(),
    | record(), mark(), tag()). A slow request is logged with it in
    | context.trace; for any other request it is dropped. Slow queries logged
    | during the request carry the same context.trace_id.
    |
    | 'max_spans' and 'max_marks' cap what one request keeps (spans over the
    | cap are summed up by name), 'top_queries' is how many of the costliest
    | statements are stored, 'max_bytes' caps the stored trace.
    */
    'trace' => [
        'enabled'     => env('METRICS_TRACE', true),
        'max_spans'   => 50,
        'max_marks'   => 50,
        'top_queries' => 5,
        'max_bytes'   => 16384,
    ],

    /*
    |--------------------------------------------------------------------------
    | Middleware Group
    |--------------------------------------------------------------------------
    | When track_requests is true, which middleware group to append to.
    | Use 'api' for API-only, 'web' for web-only, or null for global.
    */
    'middleware_group' => null,

    /*
    |--------------------------------------------------------------------------
    | Metrics Collectors
    |--------------------------------------------------------------------------
    | List of collector classes that gather metrics on each collection run.
    | Each must implement Npabisz\LaravelMetrics\Collectors\CollectorInterface.
    | You can add your own custom collectors here.
    */
    'collectors' => [
        Npabisz\LaravelMetrics\Collectors\HttpCollector::class,
        Npabisz\LaravelMetrics\Collectors\QueueCollector::class,
        Npabisz\LaravelMetrics\Collectors\DatabaseCollector::class,
        Npabisz\LaravelMetrics\Collectors\RedisCollector::class,
        Npabisz\LaravelMetrics\Collectors\SystemCollector::class,
        // Npabisz\LaravelMetrics\Collectors\HorizonCollector::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention (days)
    |--------------------------------------------------------------------------
    | How long to keep monitoring data before cleanup.
    */
    'retention' => [
        'metrics'   => env('METRICS_RETENTION_METRICS', 30),
        'slow_logs' => env('METRICS_RETENTION_SLOW_LOGS', 14),
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Metric Aggregation
    |--------------------------------------------------------------------------
    | Defines how custom metrics are aggregated for hourly rollups and
    | dashboard summaries. Each entry maps a key pattern to a strategy.
    |
    | Strategies:
    |   'sum'     — sum across samples (default for counters)
    |   'avg'     — weighted average using weight_key (e.g. _calls)
    |   'max'     — maximum value across samples
    |   'latest'  — use latest sample value (gauges/snapshots)
    |
    | Patterns support '*' wildcards. First match wins.
    | Keys not matching any pattern are auto-detected:
    |   *_avg_*  → avg (weight auto-discovered from *_calls/*_requests/*_count)
    |   *_max_*, *_p95_*, *_p99_* → max
    |   Known gauge suffixes (_percent, _mb, _count, etc.) → latest
    |   Everything else → sum
    */
    'aggregation' => [
        // Example:
        // ['pattern' => 'my_custom_avg', 'strategy' => 'avg', 'weight_key' => 'my_custom_total'],
        // ['pattern' => 'my_snapshot_*', 'strategy' => 'latest'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Prefix
    |--------------------------------------------------------------------------
    | Prefix for temporary Redis keys used to aggregate metrics between
    | collections. Uses a separate prefix to avoid collisions.
    */
    'redis_prefix' => env('METRICS_REDIS_PREFIX', 'monitoring:'),

    /*
    |--------------------------------------------------------------------------
    | Redis Connection
    |--------------------------------------------------------------------------
    | Which Redis connection to use for temporary metric counters.
    | Set to null to use the default connection.
    */
    'redis_connection' => env('METRICS_REDIS_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Batching
    |--------------------------------------------------------------------------
    |
    | When enabled, counters and lists recorded during a request, a queue job
    | or a console command are kept in memory and sent to Redis in a single
    | round trip once the response has gone out (or the job or command has
    | finished), instead of one round trip per value - with query tracking on,
    | two for every database query. 'size' is how many values may wait before
    | they are sent anyway, which bounds what a long-running process holds.
    | Values recorded by a request that dies of a fatal error are lost.
    |
    */

    'batch' => [
        'enabled' => env('METRICS_BATCH', true),
        'size' => env('METRICS_BATCH_SIZE', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    | Enable built-in JSON API routes for reading metrics.
    | When enabled, routes are registered under the given prefix.
    */
    'routes' => [
        'enabled'    => env('METRICS_ROUTES_ENABLED', false),
        'prefix'     => 'api/monitoring',
        'token'      => env('METRICS_API_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    | Web dashboard with charts, summary cards, and slow log browser.
    | Protected by Gate 'viewMetrics' (IP-based by default).
    */
    'dashboard' => [
        'enabled' => env('METRICS_DASHBOARD_ENABLED', true),
        'path'    => env('METRICS_DASHBOARD_PATH', 'metrics'),

        // Time range buttons, minutes => label. Ranges over 48h read the
        // hourly rollups, e.g. add 10080 => '7d' or 43200 => '30d'.
        'periods'        => [15 => '15m', 60 => '1h', 360 => '6h', 1440 => '24h'],
        'default_period' => 60,

        // Auto-refresh interval in seconds (0 disables it).
        'refresh' => 30,

        // Rules behind the status pill in the header ("All systems OK").
        'status' => [
            ['label' => 'Error rate', 'value' => 'error_rate', 'warn' => '> 1', 'error' => '> 5'],
            ['label' => 'Queue depth', 'value' => 'queue_depth_default', 'warn' => '> 100'],
        ],

        // Display names for the part a '*' pattern matched (table rows,
        // breakdown(), argmax()), e.g. ['payment' => 'Payments'].
        'names' => [],

        /*
        |----------------------------------------------------------------------
        | Views (Tabbed Dashboard Pages)
        |----------------------------------------------------------------------
        | Each view has a label and a list of sections. When empty (default),
        | a single page shows the built-in panels, every custom metric as a
        | tile, and the slow log. See README "Dashboard Views" for every option.
        |
        | Section types:
        |   'stats'     — KPI strip: 'items' (or 'keys' patterns for one tile
        |                  per matching custom metric)
        |   'panel'     — card with 'title', 'caption' and 'blocks' (chart,
        |                  stats, rows, meters, table, ranked, trends, hero,
        |                  note); 'columns' for a side-by-side layout, 'figure'
        |                  for a single big number, 'wide' to span the row
        |   'slow-logs' — slow requests/queries; 'log_type', 'route' pattern,
        |                  'controls', 'limit'
        |   'built-in', 'cards', 'list', 'chart', 'all-custom-cards' — the
        |                  original types, still supported
        |
        | Values are metric keys (built-in summary fields first, then custom
        | metrics) or expressions: 'a / b * 100', 'sum(container_*_mb)'.
        | Text fields take placeholders: '{key}', '{key|ms}', '{period}'.
        |
        | Example:
        |   [
        |       'label' => 'Overview',
        |       'sections' => [
        |           ['type' => 'stats', 'items' => [
        |               ['label' => 'Requests', 'value' => 'http_requests_total', 'sub' => '{http_requests_5xx} errors', 'spark' => true],
        |               ['label' => 'Error rate', 'value' => 'error_rate', 'status' => ['warn' => '> 1', 'error' => '> 5']],
        |           ]],
        |           ['type' => 'panel', 'title' => 'Payments', 'caption' => 'calls / min', 'blocks' => [
        |               ['chart' => ['series' => ['payment_api_calls'], 'highlight' => ['value' => 'payment_api_errors', 'when' => '> 0', 'label' => 'has errors']]],
        |               ['stats' => [['label' => 'Avg', 'value' => 'payment_api_avg_ms'], ['label' => 'Errors', 'value' => 'payment_api_errors']]],
        |           ]],
        |           ['type' => 'slow-logs'],
        |       ],
        |   ],
        */
        'views' => [],

    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed IPs
    |--------------------------------------------------------------------------
    | Comma-separated list of IPs allowed to access the dashboard.
    | In local environment all IPs are allowed.
    */
    'allowed_ips' => env('METRICS_ALLOWED_IPS'),

    /*
    |--------------------------------------------------------------------------
    | Alerts (programmatic callback)
    |--------------------------------------------------------------------------
    | Optional alert thresholds checked on every collection run.
    | Set callback via MetricsService::onAlert().
    */
    'alerts' => [
        'queue_depth_high'       => env('METRICS_ALERT_QUEUE_HIGH', 100),
        'queue_depth_default'    => env('METRICS_ALERT_QUEUE_DEFAULT', 500),
        'http_error_rate_5xx'    => env('METRICS_ALERT_5XX_RATE', 0.05),
        'cpu_load'               => env('METRICS_ALERT_CPU', 5.0),
        'disk_free_gb'           => env('METRICS_ALERT_DISK_GB', 2.0),
        'redis_memory_mb'       => env('METRICS_ALERT_REDIS_MB', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    | Scheduled alert notifications (every 15 min). Aggregates metrics from
    | the last 15 minutes and sends a notification if thresholds are exceeded.
    |
    | Supported channels: 'mail', 'slack', 'discord', 'google_chat'
    */
    'notifications' => [
        'enabled'  => env('METRICS_NOTIFICATIONS_ENABLED', false),

        // How often to run alert checks (minutes). Lookback window matches this value.
        'interval' => env('METRICS_ALERT_INTERVAL', 1),

        // Cooldown per alert key (minutes). After firing, same alert won't re-fire until cooldown expires.
        'cooldown' => env('METRICS_ALERT_COOLDOWN', 60),

        // Custom app name for notifications. If null, uses app.name + environment.
        'app_name' => env('METRICS_APP_NAME'),

        // Include a link to the dashboard in notifications.
        'include_dashboard_url' => env('METRICS_NOTIFICATION_DASHBOARD_URL', false),

        // Which channels to send alerts to (array of channel names)
        'channels' => array_filter(explode(',', env('METRICS_NOTIFICATION_CHANNELS', 'mail'))),

        // Channel-specific configuration
        'mail' => [
            'to' => env('METRICS_NOTIFICATION_MAIL_TO'),  // comma-separated emails
        ],
        'slack' => [
            'webhook_url' => env('METRICS_NOTIFICATION_SLACK_URL'),
        ],
        'discord' => [
            'webhook_url' => env('METRICS_NOTIFICATION_DISCORD_URL'),
        ],
        'google_chat' => [
            'webhook_url' => env('METRICS_NOTIFICATION_GOOGLE_CHAT_URL'),
        ],

        // Thresholds for 15-minute aggregated checks
        'thresholds' => [
            'error_rate_5xx'    => env('METRICS_NOTIFY_ERROR_RATE', 0.05),
            'queue_depth'       => env('METRICS_NOTIFY_QUEUE_DEPTH', 100),
            'queue_jobs_failed' => env('METRICS_NOTIFY_FAILED_JOBS', 10),
            'avg_response_ms'   => env('METRICS_NOTIFY_AVG_RESPONSE', 2000),
            'max_response_ms'   => env('METRICS_NOTIFY_MAX_RESPONSE', 10000),
            'cpu_load'          => env('METRICS_NOTIFY_CPU', 5.0),
            'disk_free_gb'      => env('METRICS_NOTIFY_DISK_GB', 2.0),
            'redis_memory_mb'   => env('METRICS_NOTIFY_REDIS_MB', 500),
            'slow_queries'      => env('METRICS_NOTIFY_SLOW_QUERIES', 50),

            // Custom thresholds for app-specific metrics (from custom JSON column)
            // Example:
            // [
            //     'key' => 'my_custom_metric', 'threshold' => 10, 'operator' => '>',
            //     'severity' => 'critical', 'label' => 'My Custom Metric',
            // ],
            'custom' => [],
        ],
    ],
];
