# Laravel Metrics

Centralized monitoring package for Laravel applications. Collects metrics every minute, tracks slow queries and requests, provides a web dashboard, JSON API, and scheduled alert notifications via mail, Slack, Discord, or Google Chat.

## Requirements

- PHP 8.1+
- Laravel 9 / 10 / 11 / 12
- Redis (for temporary metric aggregation between collection intervals)

## Installation

```bash
composer require npabisz/laravel-metrics
```

Publish config and migrations:

```bash
php artisan vendor:publish --tag=metrics-config
php artisan vendor:publish --tag=metrics-migrations
php artisan migrate
```

Optionally publish the dashboard view for customization:

```bash
php artisan vendor:publish --tag=metrics-views
```

## How It Works

The package operates in four layers:

**1. Real-time tracking** (middleware + listener) — runs on every request:
- `RequestMonitor` middleware measures request duration, increments Redis counters, logs slow requests to DB
- `QueryListener` listens for `QueryExecuted` events, counts queries, logs slow ones to DB

**2. Periodic collection** (cron every minute):
- `monitoring:collect` runs all registered collectors, reads+flushes Redis counters, stores one aggregated row in `metrics`

**3. Scheduled alerts** (cron, configurable interval):
- `monitoring:alert` aggregates metrics from the configured lookback window, evaluates thresholds, sends notifications if issues are detected
- Supports per-alert cooldowns to prevent notification fatigue

**4. Hourly aggregation** (cron every hour):
- `monitoring:aggregate-hourly` rolls up per-minute data into `metrics_hourly` for efficient long-range queries (days/weeks)

**5. Cleanup** (cron daily at 04:00):
- `monitoring:clean` removes data older than retention period

All scheduled commands are registered automatically — no changes to `Kernel.php` needed.

## Configuration

All settings are in `config/metrics.php`. Key environment variables:

```env
# Core
MONITORING_ENABLED=true
MONITORING_SLOW_QUERY_MS=100
MONITORING_TRACK_QUERIES=true
MONITORING_TRACK_REQUESTS=true

# Dashboard (web UI, IP-protected)
MONITORING_DASHBOARD_ENABLED=true
MONITORING_DASHBOARD_PATH=metrics
MONITORING_ALLOWED_IPS=123.45.67.89,98.76.54.32

# JSON API (bearer token auth)
MONITORING_ROUTES_ENABLED=false
MONITORING_API_TOKEN=your-secret-token

# Redis
MONITORING_REDIS_PREFIX=monitoring:
MONITORING_REDIS_CONNECTION=default

# Retention
MONITORING_RETENTION_METRICS=30
MONITORING_RETENTION_SLOW_LOGS=14

# Database (optional separate connection)
MONITORING_DB_CONNECTION=null

# Alerts
MONITORING_ALERT_INTERVAL=1          # minutes between alert checks
MONITORING_ALERT_COOLDOWN=60         # default cooldown per alert (minutes)
```

### Slow Request Thresholds

Define per-route pattern thresholds in `config/metrics.php`. First match wins, `*` is the fallback:

```php
'slow_request_thresholds' => [
    'api/payments/*'  => 500,   // 500ms for critical routes
    'api/*'           => 1000,  // 1s for API routes
    '*'               => 2000,  // 2s for everything else
],
```

### Middleware Group

By default, request tracking is registered as global middleware. To limit it to a specific group:

```php
'middleware_group' => 'api',  // only track API routes
```

Set `track_requests` to `false` and apply `RequestMonitor::class` middleware manually for full control.

### Custom Metric Aggregation

Define how custom metrics are aggregated for hourly rollups and dashboard summaries:

```php
'aggregation' => [
    ['pattern' => 'checkout_avg_*',  'strategy' => 'avg', 'weight_key' => 'checkout_requests'],
    ['pattern' => 'my_snapshot_*',   'strategy' => 'latest'],
    ['pattern' => 'my_peak_*',      'strategy' => 'max'],
],
```

Strategies: `sum` (default), `avg` (weighted), `max`, `latest`. Unmatched keys are auto-detected by naming convention (`*_avg_*` → avg, `*_max_*` → max, gauge suffixes → latest).

## Dashboard

Available at `/{path}` (default: `/metrics`). Protected by the `viewMonitoring` gate.

**Features:**
- Summary cards — HTTP requests, response times, error rate, queue depth, DB queries, Redis stats, CPU, disk
- Timeline charts — requests/min, response time (avg+max), queue depth, DB queries/min
- Custom metric cards and charts — display any data from your custom collectors
- Ranked list sections — display top-N items (e.g. top domains by webhook count)
- Tabbed views — organize metrics into multiple pages for readability
- Slow logs browser — filterable table (all / requests / queries), sorted by duration or time, paginated
- Auto-refresh — configurable interval (10s / 30s / 60s)
- Period selector — 5min to 30 days
- Multi-resolution — raw 1-min data for short periods, 5-min grouped for mid-range, hourly rollups for days/weeks
- Crosshair sync — hovering on any chart shows a synced vertical line across all charts for time correlation
- Configurable chart labels — short display names via `labels` config, or dynamic labels via `labels_from`
- Configurable card colors — threshold-based color rules via `value_colors`
- Tooltip formatting — auto-detects units from key names, or uses explicit `format` config

### Authorization

The dashboard uses a `viewMonitoring` gate with IP-based authorization (similar to Laravel Horizon):

- **Local environment** — all access allowed
- **Production** — only IPs listed in `MONITORING_ALLOWED_IPS` are allowed
- Denied access is logged

To customize the gate, override it in a service provider:

```php
Gate::define('viewMonitoring', function ($user = null) {
    // your custom logic
});
```

### Dashboard Views

When your application has many custom metrics, you can organize the dashboard into multiple tabbed pages using the `views` config. Each view has a label and an array of sections.

When `views` is empty (default), the dashboard shows a single page with all built-in panels, custom metric cards, and slow logs.

```php
// config/metrics.php → dashboard.views
'views' => [
    [
        'label' => 'Overview',
        'sections' => [
            ['type' => 'built-in'],
            ['type' => 'cards', 'label' => 'Business', 'keys' => ['active_users', 'orders_total', 'revenue_usd']],
            ['type' => 'slow-logs'],
        ],
    ],
    [
        'label' => 'External APIs',
        'sections' => [
            ['type' => 'cards', 'label' => 'Payment Gateway', 'keys' => ['payment_api_*']],
            ['type' => 'chart', 'label' => 'Payment Calls', 'keys' => ['payment_api_calls', 'payment_api_errors'],
                'labels' => ['payment_api_calls' => 'Calls', 'payment_api_errors' => 'Errors'],
                'colors' => ['payment_api_errors' => 'red', 'payment_api_calls' => 'blue']],
        ],
    ],
    [
        'label' => 'Infrastructure',
        'sections' => [
            ['type' => 'cards', 'label' => 'Resources', 'keys' => ['cpu_usage_*', 'memory_*']],
            ['type' => 'chart', 'label' => 'CPU & Memory', 'keys' => ['cpu_usage_percent', 'memory_usage_percent'],
                'labels' => ['cpu_usage_percent' => 'CPU', 'memory_usage_percent' => 'Memory'],
                'chart_type' => 'line', 'gauge' => true, 'format' => ['suffix' => '%']],
        ],
    ],
],
```

#### Section Types

| Type | Description |
|------|-------------|
| `built-in` | Built-in card group. Set `'id'` to show only one: `'http'`, `'queue'`, `'database'`, `'system'`. Omit `id` for all four + timeline charts. |
| `cards` | Summary cards for custom metric keys matching `'keys'` patterns. Supports `value_colors` for threshold-based coloring. |
| `list` | Ranked list pairing `label_keys` with `value_keys` patterns (e.g. top N items). |
| `chart` | Chart panel for custom metric keys. Consecutive charts are grouped in a 3-column grid. |
| `slow-logs` | The slow logs table with type filters, sort buttons, and pagination. |

#### Chart/Card Options

| Option | Description |
|--------|-------------|
| `label` | Section title displayed above cards or as chart title. |
| `keys` | Array of metric key names. Supports `*` wildcard anywhere (e.g. `payment_*_calls`, `*_errors`). |
| `labels` | Keyed map of short display names: `['payment_api_calls' => 'Calls']`. |
| `labels_from` | Dynamic labels from custom summary: `['metric_count_key' => 'metric_label_key']`. |
| `colors` | Indexed array `['blue', 'red']` or keyed map `['errors' => 'red']`. Available: blue, red, green, yellow, purple, cyan, orange, pink. |
| `chart_type` | `'bar'` (default) or `'line'`. |
| `gauge` | `true` to use latest value instead of sum for aggregation. |
| `format` | `['suffix' => '%', 'decimals' => 1]` — value formatting for tooltips and Y-axis. |
| `value_colors` | Card color rules: `[['key' => '*avg*', 'conditions' => [['op' => '<', 'value' => 150, 'color' => 'green']]]]`. |

#### List Section Options

| Option | Description |
|--------|-------------|
| `label_keys` | Patterns matching keys that hold display labels (e.g. `['top*_domain']`). |
| `value_keys` | Patterns matching keys that hold numeric values (e.g. `['top*_count']`). |
| `max` | Maximum number of items to display (default: 5). |

The same metric key can appear in multiple views. Tabs persist across page refreshes (via localStorage) and are linkable via URL hash (e.g. `#infrastructure`).

## JSON API

Enable with `MONITORING_ROUTES_ENABLED=true`. Protected by bearer token (`Authorization: Bearer <token>`).

```bash
# Summary (last N minutes)
curl -H "Authorization: Bearer your-token" \
  https://app.example.com/api/monitoring/summary?minutes=5

# Full metrics timeline
curl -H "Authorization: Bearer your-token" \
  https://app.example.com/api/monitoring/metrics?minutes=60

# Slow logs
curl -H "Authorization: Bearer your-token" \
  https://app.example.com/api/monitoring/slow-logs?minutes=60&type=query&limit=50
```

### Endpoints

| Method | Endpoint | Parameters | Description |
|--------|----------|------------|-------------|
| GET | `/summary` | `minutes` (default: 5) | Aggregated summary |
| GET | `/metrics` | `minutes` (default: 60, max: 43200) | Raw metric rows |
| GET | `/slow-logs` | `minutes`, `type` (query/request), `limit` (default: 50, max: 200) | Slow log entries |

## Artisan Commands

| Command | Schedule | Description |
|---------|----------|-------------|
| `monitoring:collect` | Every minute | Collect metrics from all collectors and store in DB |
| `monitoring:alert` | Configurable (default: every minute) | Evaluate aggregated thresholds, send notifications with cooldown |
| `monitoring:alert --test` | Manual | Send a test notification to verify channel configuration |
| `monitoring:aggregate-hourly` | Every hour | Roll up per-minute metrics into hourly table for long-range queries |
| `monitoring:aggregate-hourly --hours=168` | Manual | Backfill hourly rollups for the last N hours |
| `monitoring:status` | Manual | Display current metrics in terminal tables |
| `monitoring:status --minutes=15` | Manual | Display metrics for a custom time range |
| `monitoring:clean` | Daily 04:00 | Remove data older than retention period |

## Notifications

Scheduled alert notifications at a configurable interval (default: every minute). Aggregates metrics from the lookback window, evaluates thresholds, and sends alerts with per-alert cooldowns.

### Supported Channels

| Channel | Config Key | Env Variable | Format |
|---------|-----------|-------------|--------|
| Mail | `notifications.mail.to` | `MONITORING_NOTIFICATION_MAIL_TO` | HTML email with "Open Dashboard" button |
| Slack | `notifications.slack.webhook_url` | `MONITORING_NOTIFICATION_SLACK_URL` | Slack message with markdown formatting |
| Discord | `notifications.discord.webhook_url` | `MONITORING_NOTIFICATION_DISCORD_URL` | Rich embed with color, fields, timestamp |
| Google Chat | `notifications.google_chat.webhook_url` | `MONITORING_NOTIFICATION_GOOGLE_CHAT_URL` | Text message with emoji and dashboard link |

Multiple channels can be used simultaneously.

### Configuration

```env
MONITORING_NOTIFICATIONS_ENABLED=true
MONITORING_ALERT_INTERVAL=1              # check every N minutes
MONITORING_ALERT_COOLDOWN=60             # default cooldown per alert (minutes)

# Comma-separated channel list
MONITORING_NOTIFICATION_CHANNELS=discord,google_chat

# Channel-specific webhook URLs
MONITORING_NOTIFICATION_DISCORD_URL=https://discord.com/api/webhooks/...
MONITORING_NOTIFICATION_GOOGLE_CHAT_URL=https://chat.googleapis.com/v1/spaces/.../messages?key=...&token=...
MONITORING_NOTIFICATION_SLACK_URL=https://hooks.slack.com/services/...
MONITORING_NOTIFICATION_MAIL_TO=admin@example.com,ops@example.com
```

### Notification Thresholds

Thresholds are evaluated against aggregated data from the configured interval:

| Threshold | Env Variable | Default | Triggers When |
|-----------|-------------|---------|---------------|
| 5xx error rate | `MONITORING_NOTIFY_ERROR_RATE` | 0.05 (5%) | Error rate exceeds percentage |
| Queue depth | `MONITORING_NOTIFY_QUEUE_DEPTH` | 100 | Total pending jobs exceed count |
| Failed jobs | `MONITORING_NOTIFY_FAILED_JOBS` | 10 | Failed jobs exceed count |
| Avg response | `MONITORING_NOTIFY_AVG_RESPONSE` | 2000ms | Average response time exceeds threshold |
| Max response | `MONITORING_NOTIFY_MAX_RESPONSE` | 10000ms | Max response time exceeds threshold |
| CPU load | `MONITORING_NOTIFY_CPU` | 5.0 | 1-minute load average exceeds threshold |
| Disk free | `MONITORING_NOTIFY_DISK_GB` | 2.0 GB | Free disk space drops below threshold |
| Redis memory | `MONITORING_NOTIFY_REDIS_MB` | 500 MB | Redis memory usage exceeds threshold |
| Slow queries | `MONITORING_NOTIFY_SLOW_QUERIES` | 50 | Slow queries exceed count |

### Custom Thresholds

Define custom alert rules based on any metric, including custom collector data stored in the `custom` JSON column:

```php
// config/metrics.php → notifications.thresholds.custom
'custom' => [
    [
        'key'       => 'payment_errors',    // metric key (from custom JSON)
        'threshold' => 5,
        'operator'  => '>',                 // >, >=, <, <=
        'severity'  => 'critical',          // critical or warning
        'label'     => 'Payment Errors',    // display name in notification
        'cooldown'  => 30,                  // optional per-alert cooldown (minutes)
    ],
],
```

### Alert Cooldowns

After an alert fires, it enters a cooldown period during which it won't fire again. This prevents notification fatigue for persistent issues.

- **Global default**: `notifications.cooldown` (default: 60 minutes)
- **Per-alert override**: set `'cooldown' => 30` on any custom threshold rule
- **Disable cooldown**: set `'cooldown' => 0` for an alert that should fire every check

### Testing Notifications

Send a test notification with sample data to verify channel configuration:

```bash
php artisan monitoring:alert --test
```

## Collectors

Built-in collectors:

| Collector | Metrics |
|-----------|---------|
| `HttpCollector` | requests total/2xx/4xx/5xx, avg/max duration, slow count |
| `QueueCollector` | queue depths (high/default/low priority), jobs processed/failed |
| `DatabaseCollector` | queries total, slow queries, avg/max query duration |
| `RedisCollector` | memory usage, connected clients, ops/sec, cache hit rate |
| `SystemCollector` | CPU load (1m), memory usage, disk free |
| `HorizonCollector` | Horizon supervisor status (disabled by default) |

### Custom Collectors

Implement `CollectorInterface` and add to config:

```php
<?php

namespace App\Monitoring\Collectors;

use Npabisz\LaravelMetrics\Collectors\CollectorInterface;

class MyCustomCollector implements CollectorInterface
{
    public function collect(): array
    {
        return [
            'custom' => [
                'active_users' => User::where('last_seen', '>=', now()->subMinutes(15))->count(),
                'pending_orders' => Order::where('status', 'pending')->count(),
            ],
        ];
    }
}
```

```php
// config/metrics.php
'collectors' => [
    // ... built-in collectors
    App\Monitoring\Collectors\MyCustomCollector::class,
],
```

### Instrumenting Application Code

Use `MonitoringService` to push real-time metrics from anywhere in your application. These are aggregated by collectors on the next `monitoring:collect` run.

```php
use Npabisz\LaravelMetrics\Services\MonitoringService;

$monitoring = app(MonitoringService::class);

// Increment a counter
$monitoring->increment('my_group', 'api_calls');
$monitoring->increment('my_group', 'errors');

// Track durations for avg/max calculation
$start = microtime(true);
$result = doSomething();
$duration = (microtime(true) - $start) * 1000;
$monitoring->pushToList('my_group', 'durations', round($duration, 1));
```

Then create a collector that reads these counters:

```php
public function collect(): array
{
    $data = app(MonitoringService::class)->flushRedisCounters('my_group');
    $durations = MonitoringService::decodeDurations($data, 'durations');

    return [
        'custom' => [
            'my_api_calls'  => (int) ($data['api_calls'] ?? 0),
            'my_api_avg_ms' => MonitoringService::avgDuration($durations),
            'my_api_max_ms' => MonitoringService::maxDuration($durations),
        ],
    ];
}
```

## Programmatic Alerts

In addition to scheduled notifications, you can register a callback for real-time alerts triggered on every `monitoring:collect` run:

```php
// In a service provider
use Npabisz\LaravelMetrics\Services\MonitoringService;

MonitoringService::onAlert(function (string $alert, $value, $threshold) {
    Log::warning("Monitoring alert: {$alert}", [
        'value' => $value,
        'threshold' => $threshold,
    ]);
});
```

## Database Tables

### `metrics`

One row per minute. Stores aggregated metrics from all collectors. Includes a `custom` JSON column for extensibility. Auto-cleaned after 30 days (configurable).

### `metrics_hourly`

One row per hour. Pre-aggregated from `metrics` by the `monitoring:aggregate-hourly` command. Used for dashboard queries spanning days or weeks.

### `metrics_slow_logs`

One row per slow query or slow request. Stores SQL/URL, duration, user_id, context. Auto-cleaned after 14 days (configurable).

All tables support a separate database connection via `MONITORING_DB_CONNECTION`.

## Multi-Resolution Data

The dashboard automatically selects the appropriate data resolution based on the selected time period:

| Period | Source | Resolution |
|--------|--------|-----------|
| < 2 hours | `metrics` | 1-minute (raw) |
| 2–48 hours | `metrics` | 5-minute (grouped in memory) |
| > 48 hours | `metrics_hourly` | 1-hour (pre-aggregated) |

## Data Volume

At 1 row/minute, `metrics` generates ~43,200 rows/month. With a 30-day retention, the table stays under 50K rows.

`metrics_hourly` generates ~720 rows/month. With default retention, stays well under 10K rows.

`metrics_slow_logs` volume depends on your thresholds and traffic. Tune `slow_query_threshold` and `slow_request_thresholds` to control noise.

## Package Structure

```
src/
├── Collectors/
│   ├── CollectorInterface.php
│   ├── HttpCollector.php
│   ├── QueueCollector.php
│   ├── DatabaseCollector.php
│   ├── RedisCollector.php
│   ├── SystemCollector.php
│   └── HorizonCollector.php
├── Commands/
│   ├── MonitoringCollectCommand.php
│   ├── MonitoringAggregateHourlyCommand.php
│   ├── MonitoringAlertCommand.php
│   ├── MonitoringStatusCommand.php
│   └── MonitoringCleanCommand.php
├── Http/
│   ├── Controllers/
│   │   ├── DashboardController.php
│   │   └── MonitoringController.php
│   └── Middleware/
│       ├── AuthorizeBearerToken.php
│       └── AuthorizeMonitoring.php
├── Listeners/
│   └── QueryListener.php
├── Middleware/
│   └── RequestMonitor.php
├── Models/
│   ├── MonitoringMetric.php
│   ├── MonitoringMetricHourly.php
│   └── MonitoringSlowLog.php
├── Notifications/
│   ├── Channels/
│   │   ├── DiscordChannel.php
│   │   └── GoogleChatChannel.php
│   ├── MonitoringAlertNotifiable.php
│   └── MonitoringAlertNotification.php
├── Services/
│   ├── MetricAggregator.php
│   └── MonitoringService.php
└── MonitoringServiceProvider.php
```

## License

MIT
