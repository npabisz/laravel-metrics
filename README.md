# Laravel Monitoring

Centralized monitoring package for Laravel applications. Collects metrics every minute, tracks slow queries and requests, provides a web dashboard, JSON API, and scheduled alert notifications via mail, Slack, Discord, or Google Chat.

## Requirements

- PHP 8.1+
- Laravel 9 / 10 / 11
- Redis (for temporary metric aggregation between collection intervals)

## Installation

```bash
composer require npabisz/laravel-monitoring
```

Publish config and migrations:

```bash
php artisan vendor:publish --tag=monitoring-config
php artisan vendor:publish --tag=monitoring-migrations
php artisan migrate
```

Optionally publish the dashboard view for customization:

```bash
php artisan vendor:publish --tag=monitoring-views
```

## How It Works

The package operates in three layers:

**1. Real-time tracking** (middleware + listener) — runs on every request:
- `RequestMonitor` middleware measures request duration, increments Redis counters, logs slow requests to DB
- `QueryListener` listens for `QueryExecuted` events, counts queries, logs slow ones to DB

**2. Periodic collection** (cron every minute):
- `monitoring:collect` runs all registered collectors, reads+flushes Redis counters, stores one aggregated row in `monitoring_metrics`

**3. Scheduled alerts** (cron every 15 minutes):
- `monitoring:alert` aggregates metrics from the last 15 minutes, evaluates thresholds, sends notifications if issues are detected

**4. Cleanup** (cron daily at 04:00):
- `monitoring:clean` removes data older than retention period

All scheduled commands are registered automatically — no changes to `Kernel.php` needed.

## Configuration

All settings are in `config/monitoring.php`. Key environment variables:

```env
# Core
MONITORING_ENABLED=true
MONITORING_SLOW_QUERY_MS=100
MONITORING_TRACK_QUERIES=true
MONITORING_TRACK_REQUESTS=true

# Dashboard (web UI, IP-protected)
MONITORING_DASHBOARD_ENABLED=true
MONITORING_DASHBOARD_PATH=monitoring
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
```

### Slow Request Thresholds

Define per-route pattern thresholds in `config/monitoring.php`. First match wins, `*` is the fallback:

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

## Dashboard

Available at `/{path}` (default: `/monitoring`). Protected by the `viewMonitoring` gate.

**Features:**
- Summary cards — HTTP requests, response times, error rate, queue depth, DB queries, Redis stats, CPU, disk
- Timeline charts — requests/min, response time (avg+max), queue depth, DB queries/min
- Custom metric cards and charts — display any data from your custom collectors
- Tabbed views — organize metrics into multiple pages for readability
- Slow logs browser — filterable table (all / requests / queries), sorted by duration or time, paginated
- Auto-refresh — configurable interval (10s / 30s / 60s)
- Period selector — 5min / 15min / 30min / 1h / 6h / 24h

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
// config/monitoring.php → dashboard.views
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
            ['type' => 'chart', 'label' => 'Payment Calls', 'keys' => ['payment_api_calls', 'payment_api_errors'], 'colors' => ['blue', 'red']],
            ['type' => 'cards', 'label' => 'Email Service', 'keys' => ['email_api_*']],
            ['type' => 'chart', 'label' => 'Email Delivery', 'keys' => ['email_api_calls', 'email_api_errors', 'email_api_bounces']],
        ],
    ],
    [
        'label' => 'Infrastructure',
        'sections' => [
            ['type' => 'cards', 'label' => 'Resources', 'keys' => ['cpu_usage_*', 'memory_*', 'net_*']],
            ['type' => 'chart', 'label' => 'CPU & Memory', 'keys' => ['cpu_usage_percent', 'memory_usage_percent'], 'chart_type' => 'line', 'gauge' => true, 'format' => ['suffix' => '%']],
        ],
    ],
],
```

#### Section Types

| Type | Description |
|------|-------------|
| `built-in` | Built-in card group. Set `'id'` to show only one: `'http'`, `'queue'`, `'database'`, `'system'`. Omit `id` for all four + timeline charts. |
| `cards` | Summary cards for custom metric keys matching `'keys'` patterns. |
| `chart` | A chart panel for custom metric keys matching `'keys'` patterns. |
| `slow-logs` | The slow logs table with type filters, sort buttons, and pagination. |

#### Chart/Card Options

| Option | Description |
|--------|-------------|
| `label` | Section title displayed above cards or as chart title. |
| `keys` | Array of metric key names. Supports `*` wildcard (e.g. `payment_api_*`). |
| `colors` | Indexed array `['blue', 'red']` or keyed map `['errors' => 'red', 'calls' => 'blue']`. Available: blue, red, green, yellow, purple, cyan, orange, pink. |
| `chart_type` | `'bar'` (default) or `'line'`. |
| `gauge` | `true` to use latest value instead of sum for aggregation. Use for metrics like CPU %, memory, averages. |
| `format` | `['suffix' => '%', 'decimals' => 1, 'multiply' => 1]` — custom value formatting. |

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
| GET | `/metrics` | `minutes` (default: 60, max: 1440) | Raw metric rows |
| GET | `/slow-logs` | `minutes`, `type` (query/request), `limit` (default: 50, max: 200) | Slow log entries |

## Artisan Commands

| Command | Schedule | Description |
|---------|----------|-------------|
| `monitoring:collect` | Every minute | Collect metrics from all collectors and store in DB |
| `monitoring:alert` | Every 15 min | Evaluate 15-min aggregated thresholds, send notifications |
| `monitoring:alert --test` | Manual | Send a test notification to verify channel configuration |
| `monitoring:status` | Manual | Display current metrics in terminal tables |
| `monitoring:status --minutes=15` | Manual | Display metrics for a custom time range |
| `monitoring:clean` | Daily 04:00 | Remove data older than retention period |

## Notifications

Scheduled alert notifications every 15 minutes. Aggregates metrics from the last 15 minutes, evaluates thresholds, and sends alerts on configured channels if issues are detected.

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

# Comma-separated channel list
MONITORING_NOTIFICATION_CHANNELS=discord,google_chat

# Channel-specific webhook URLs
MONITORING_NOTIFICATION_DISCORD_URL=https://discord.com/api/webhooks/...
MONITORING_NOTIFICATION_GOOGLE_CHAT_URL=https://chat.googleapis.com/v1/spaces/.../messages?key=...&token=...
MONITORING_NOTIFICATION_SLACK_URL=https://hooks.slack.com/services/...
MONITORING_NOTIFICATION_MAIL_TO=admin@example.com,ops@example.com
```

### Notification Thresholds

Thresholds are evaluated against 15-minute aggregated data:

| Threshold | Env Variable | Default | Triggers When |
|-----------|-------------|---------|---------------|
| 5xx error rate | `MONITORING_NOTIFY_ERROR_RATE` | 0.05 (5%) | Error rate exceeds percentage |
| Queue depth | `MONITORING_NOTIFY_QUEUE_DEPTH` | 100 | Total pending jobs exceed count |
| Failed jobs | `MONITORING_NOTIFY_FAILED_JOBS` | 10 | Failed jobs in 15 min exceed count |
| Avg response | `MONITORING_NOTIFY_AVG_RESPONSE` | 2000ms | Average response time exceeds threshold |
| Max response | `MONITORING_NOTIFY_MAX_RESPONSE` | 10000ms | Max response time exceeds threshold |
| CPU load | `MONITORING_NOTIFY_CPU` | 5.0 | 1-minute load average exceeds threshold |
| Disk free | `MONITORING_NOTIFY_DISK_GB` | 2.0 GB | Free disk space drops below threshold |
| Redis memory | `MONITORING_NOTIFY_REDIS_MB` | 500 MB | Redis memory usage exceeds threshold |
| Slow queries | `MONITORING_NOTIFY_SLOW_QUERIES` | 50 | Slow queries in 15 min exceed count |

### Custom Thresholds

Define custom alert rules based on any metric, including custom collector data stored in the `custom` JSON column:

```php
// config/monitoring.php → notifications.thresholds.custom
'custom' => [
    [
        'key'       => 'payment_errors',    // metric key (from custom JSON)
        'threshold' => 5,
        'operator'  => '>',                 // >, >=, <, <=
        'severity'  => 'critical',          // critical or warning
        'label'     => 'Payment Errors',    // display name in notification
    ],
],
```

### Alert Severity

Each alert has a `severity` level (`warning` or `critical`). Some alerts auto-escalate to `critical` when the value significantly exceeds the threshold (e.g. error rate > 15%, queue depth > 5x threshold). Severity affects the visual appearance of notifications:

- **Critical** — red color, alarm emoji, `!!` prefix
- **Warning** — orange/yellow color, warning emoji, `!` prefix

### Testing Notifications

Send a test notification with sample data to verify channel configuration:

```bash
php artisan monitoring:alert --test
```

This bypasses all threshold checks and `MONITORING_NOTIFICATIONS_ENABLED` — it sends a test notification with 2 sample alerts (1 critical + 1 warning) and realistic dummy metrics to all configured channels.

### Webhook URL Setup

**Discord:**
1. Open Discord channel settings → Integrations → Webhooks → New Webhook
2. Copy the webhook URL

**Google Chat:**
1. Open the Space → Apps & integrations → Add webhooks
2. Name it (e.g. "Monitoring Alerts") → Save → Copy URL

**Slack:**
1. Go to api.slack.com/apps → Create New App → Incoming Webhooks → Activate
2. Add webhook to a channel → Copy URL

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

use Npabisz\LaravelMonitoring\Collectors\CollectorInterface;

class MyCustomCollector implements CollectorInterface
{
    public function collect(): array
    {
        // Standard columns go at root level (must match DB columns)
        // Custom data goes under 'custom' key (stored as JSON)
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
// config/monitoring.php
'collectors' => [
    // ... built-in collectors
    App\Monitoring\Collectors\MyCustomCollector::class,
],
```

### Instrumenting Application Code

Use `MonitoringService` to push real-time metrics from anywhere in your application. These are aggregated by collectors on the next `monitoring:collect` run.

```php
use Npabisz\LaravelMonitoring\Services\MonitoringService;

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
    $durations = json_decode($data['durations'] ?? '[]', true) ?: [];

    return [
        'custom' => [
            'my_api_calls' => (int) ($data['api_calls'] ?? 0),
            'my_avg_ms'    => count($durations) > 0 ? round(array_sum($durations) / count($durations), 1) : 0,
        ],
    ];
}
```

## Programmatic Alerts

In addition to scheduled notifications, you can register a callback for real-time alerts triggered on every `monitoring:collect` run:

```php
// In a service provider
use Npabisz\LaravelMonitoring\Services\MonitoringService;

MonitoringService::onAlert(function (string $alert, $value, $threshold) {
    Log::warning("Monitoring alert: {$alert}", [
        'value' => $value,
        'threshold' => $threshold,
    ]);
});
```

Programmatic alert keys:

| Alert | Triggers When | Default |
|-------|---------------|---------|
| `queue_depth_high` | High-priority queue depth exceeds threshold | 100 |
| `queue_depth_default` | Default queue depth exceeds threshold | 500 |
| `http_error_rate_5xx` | 5xx error rate exceeds percentage | 0.05 (5%) |
| `cpu_load` | 1-minute CPU load average exceeds threshold | 5.0 |
| `disk_free_gb` | Free disk space drops below threshold | 2.0 GB |
| `redis_memory_mb` | Redis memory usage exceeds threshold | 500 MB |

## Database Tables

### `monitoring_metrics`

One row per minute. Stores aggregated metrics from all collectors. Includes a `custom` JSON column for extensibility. Auto-cleaned after 30 days (configurable).

### `monitoring_slow_logs`

One row per slow query or slow request. Stores SQL/URL, duration, user_id, context. Auto-cleaned after 14 days (configurable).

Both tables support a separate database connection via `MONITORING_DB_CONNECTION`.

## Data Volume

At 1 row/minute, `monitoring_metrics` generates ~43,200 rows/month. With a 30-day retention, the table stays under 50K rows.

`monitoring_slow_logs` volume depends on your thresholds and traffic. Tune `slow_query_threshold` and `slow_request_thresholds` to control noise.

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
│   └── MonitoringSlowLog.php
├── Notifications/
│   ├── Channels/
│   │   ├── DiscordChannel.php
│   │   └── GoogleChatChannel.php
│   ├── MonitoringAlertNotifiable.php
│   └── MonitoringAlertNotification.php
├── Services/
│   └── MonitoringService.php
└── MonitoringServiceProvider.php
```

## License

MIT
