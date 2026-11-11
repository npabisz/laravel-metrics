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

        /*
        |----------------------------------------------------------------------
        | Views (Tabbed Dashboard Pages)
        |----------------------------------------------------------------------
        | Organize the dashboard into multiple tabs/pages. Each view has a
        | label and an array of sections that control what is displayed.
        |
        | When empty (default), the dashboard shows a single page with all
        | built-in panels, slow logs, and all custom metrics as cards.
        |
        | Section types:
        |   'built-in'  — renders built-in card group: 'http', 'queue',
        |                  'database', 'system' (all four if 'id' omitted)
        |   'cards'     — summary cards for matching custom metric keys
        |   'list'      — ranked list pairing label_keys with value_keys
        |                  (e.g. top N domains by count)
        |   'chart'     — a chart panel for matching custom metric keys
        |   'slow-logs' — the slow logs table with filters and pagination
        |
        | Chart/card options:
        |   'label'      — section title
        |   'keys'       — metric keys to include, supports '*' wildcard
        |   'colors'     — indexed array or keyed map (blue, red, green,
        |                   yellow, purple, cyan, orange, pink)
        |   'chart_type' — 'bar' (default) or 'line'
        |   'gauge'      — true to use latest value instead of sum
        |   'format'     — ['suffix' => '%', 'decimals' => 1, 'multiply' => 1]
        |
        | Example:
        |   [
        |       'label' => 'Overview',
        |       'sections' => [
        |           ['type' => 'built-in'],
        |           ['type' => 'cards', 'label' => 'Key Metrics', 'keys' => ['active_users', 'orders_total']],
        |           ['type' => 'slow-logs'],
        |       ],
        |   ],
        |   [
        |       'label' => 'External APIs',
        |       'sections' => [
        |           ['type' => 'cards', 'label' => 'Payment Gateway', 'keys' => ['payment_api_*']],
        |           ['type' => 'chart', 'label' => 'API Calls', 'keys' => ['payment_api_calls', 'payment_api_errors'], 'colors' => ['blue', 'red']],
        |       ],
        |   ],
        |   [
        |       'label' => 'Infrastructure',
        |       'sections' => [
        |           ['type' => 'cards', 'label' => 'Resources', 'keys' => ['cpu_usage_*', 'memory_*']],
        |           ['type' => 'chart', 'label' => 'CPU Usage', 'keys' => ['cpu_usage_percent'], 'chart_type' => 'line', 'gauge' => true, 'format' => ['suffix' => '%']],
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
