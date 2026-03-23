<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Enable/Disable Monitoring
    |--------------------------------------------------------------------------
    */
    'enabled' => env('MONITORING_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    | Which database connection to use for monitoring tables.
    | Set to null to use the default connection.
    */
    'connection' => env('MONITORING_DB_CONNECTION', null),

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
    'slow_query_threshold' => env('MONITORING_SLOW_QUERY_MS', 100),

    /*
    |--------------------------------------------------------------------------
    | Query Listener
    |--------------------------------------------------------------------------
    | Track all DB queries for aggregate metrics.
    | Set to false on high-traffic apps if overhead is a concern.
    */
    'track_queries' => env('MONITORING_TRACK_QUERIES', true),

    /*
    |--------------------------------------------------------------------------
    | Request Tracking
    |--------------------------------------------------------------------------
    | Auto-register the RequestMonitor middleware on all routes.
    | Set to false if you want to apply it manually to specific route groups.
    */
    'track_requests' => env('MONITORING_TRACK_REQUESTS', true),

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
    | Each must implement Npabisz\LaravelMonitoring\Collectors\CollectorInterface.
    | You can add your own custom collectors here.
    */
    'collectors' => [
        Npabisz\LaravelMonitoring\Collectors\HttpCollector::class,
        Npabisz\LaravelMonitoring\Collectors\QueueCollector::class,
        Npabisz\LaravelMonitoring\Collectors\DatabaseCollector::class,
        Npabisz\LaravelMonitoring\Collectors\RedisCollector::class,
        Npabisz\LaravelMonitoring\Collectors\SystemCollector::class,
        // Npabisz\LaravelMonitoring\Collectors\HorizonCollector::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention (days)
    |--------------------------------------------------------------------------
    | How long to keep monitoring data before cleanup.
    */
    'retention' => [
        'metrics'   => env('MONITORING_RETENTION_METRICS', 30),
        'slow_logs' => env('MONITORING_RETENTION_SLOW_LOGS', 14),
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Prefix
    |--------------------------------------------------------------------------
    | Prefix for temporary Redis keys used to aggregate metrics between
    | collections. Uses a separate prefix to avoid collisions.
    */
    'redis_prefix' => env('MONITORING_REDIS_PREFIX', 'monitoring:'),

    /*
    |--------------------------------------------------------------------------
    | Redis Connection
    |--------------------------------------------------------------------------
    | Which Redis connection to use for temporary metric counters.
    | Set to null to use the default connection.
    */
    'redis_connection' => env('MONITORING_REDIS_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    | Enable built-in JSON API routes for reading metrics.
    | When enabled, routes are registered under the given prefix.
    */
    'routes' => [
        'enabled'    => env('MONITORING_ROUTES_ENABLED', false),
        'prefix'     => 'api/monitoring',
        'token'      => env('MONITORING_API_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    | Web dashboard with charts, summary cards, and slow log browser.
    | Protected by Gate 'viewMonitoring' (IP-based by default).
    */
    'dashboard' => [
        'enabled' => env('MONITORING_DASHBOARD_ENABLED', true),
        'path'    => env('MONITORING_DASHBOARD_PATH', 'monitoring'),

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
    'allowed_ips' => env('MONITORING_ALLOWED_IPS'),

    /*
    |--------------------------------------------------------------------------
    | Alerts (programmatic callback)
    |--------------------------------------------------------------------------
    | Optional alert thresholds checked on every collection run.
    | Set callback via MonitoringService::onAlert().
    */
    'alerts' => [
        'queue_depth_high'       => env('MONITORING_ALERT_QUEUE_HIGH', 100),
        'queue_depth_default'    => env('MONITORING_ALERT_QUEUE_DEFAULT', 500),
        'http_error_rate_5xx'    => env('MONITORING_ALERT_5XX_RATE', 0.05),
        'cpu_load'               => env('MONITORING_ALERT_CPU', 5.0),
        'disk_free_gb'           => env('MONITORING_ALERT_DISK_GB', 2.0),
        'redis_memory_mb'       => env('MONITORING_ALERT_REDIS_MB', 500),
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
        'enabled'  => env('MONITORING_NOTIFICATIONS_ENABLED', false),

        // Custom app name for notifications. If null, uses app.name + environment.
        'app_name' => env('MONITORING_APP_NAME'),

        // Include a link to the dashboard in notifications.
        'include_dashboard_url' => env('MONITORING_NOTIFICATION_DASHBOARD_URL', false),

        // Which channels to send alerts to (array of channel names)
        'channels' => array_filter(explode(',', env('MONITORING_NOTIFICATION_CHANNELS', 'mail'))),

        // Channel-specific configuration
        'mail' => [
            'to' => env('MONITORING_NOTIFICATION_MAIL_TO'),  // comma-separated emails
        ],
        'slack' => [
            'webhook_url' => env('MONITORING_NOTIFICATION_SLACK_URL'),
        ],
        'discord' => [
            'webhook_url' => env('MONITORING_NOTIFICATION_DISCORD_URL'),
        ],
        'google_chat' => [
            'webhook_url' => env('MONITORING_NOTIFICATION_GOOGLE_CHAT_URL'),
        ],

        // Thresholds for 15-minute aggregated checks
        'thresholds' => [
            'error_rate_5xx'    => env('MONITORING_NOTIFY_ERROR_RATE', 0.05),
            'queue_depth'       => env('MONITORING_NOTIFY_QUEUE_DEPTH', 100),
            'queue_jobs_failed' => env('MONITORING_NOTIFY_FAILED_JOBS', 10),
            'avg_response_ms'   => env('MONITORING_NOTIFY_AVG_RESPONSE', 2000),
            'max_response_ms'   => env('MONITORING_NOTIFY_MAX_RESPONSE', 10000),
            'cpu_load'          => env('MONITORING_NOTIFY_CPU', 5.0),
            'disk_free_gb'      => env('MONITORING_NOTIFY_DISK_GB', 2.0),
            'redis_memory_mb'   => env('MONITORING_NOTIFY_REDIS_MB', 500),
            'slow_queries'      => env('MONITORING_NOTIFY_SLOW_QUERIES', 50),

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
