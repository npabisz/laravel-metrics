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
METRICS_ENABLED=true
METRICS_SLOW_QUERY_MS=100
METRICS_TRACK_QUERIES=true
METRICS_TRACK_REQUESTS=true

# Dashboard (web UI, IP-protected)
METRICS_DASHBOARD_ENABLED=true
METRICS_DASHBOARD_PATH=metrics
METRICS_ALLOWED_IPS=123.45.67.89,98.76.54.32

# JSON API (bearer token auth)
METRICS_ROUTES_ENABLED=false
METRICS_API_TOKEN=your-secret-token

# Redis
METRICS_REDIS_PREFIX=monitoring:
METRICS_REDIS_CONNECTION=default

# Batching: send what a request, job or command recorded in one round trip
METRICS_BATCH=true
METRICS_BATCH_SIZE=500

# Retention
METRICS_RETENTION_METRICS=30
METRICS_RETENTION_SLOW_LOGS=14

# Database (optional separate connection)
METRICS_DB_CONNECTION=null

# Alerts
METRICS_ALERT_INTERVAL=1          # minutes between alert checks
METRICS_ALERT_COOLDOWN=60         # default cooldown per alert (minutes)
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

Available at `/{path}` (default: `/metrics`). Protected by the `viewMetrics` gate.

**Features:**
- KPI strips — headline numbers with sparklines, coloured by status rules (lime = healthy, orange = needs attention, red = critical)
- Panels — charts with inline stats, key/value rows, meters, tables, ranked bars and per-label trend small multiples, composed in config
- Expressions — any value can be a metric key, arithmetic (`errors / calls * 100`) or a pattern function (`sum(container_*_memory_mb)`)
- Tabbed views — organise metrics into pages, linkable via URL hash
- Slow log — slowest or most recent requests and queries, filterable by type and route pattern, paginated
- Status pill — configurable health rules summarised in the header
- Period selector and auto-refresh — configurable ranges (default 15m / 1h / 6h / 24h) and interval (default 30 s)
- Multi-resolution — raw 1-min data for short periods, 5-min grouped for mid-range, hourly rollups for days/weeks
- Crosshair sync — hovering on any chart shows a synced vertical line across all charts for time correlation
- Pinned time — click a chart to pin that moment: a solid line marks it on every chart and sparkline, across tabs, refreshes and period changes, and slow logs narrow to that moment ±1 bucket ("Whole range" shows everything again; × in the header or Esc clears the pin)
- Built-in default — with no `views` configured, one page shows HTTP, queue, database and system panels, every custom metric, and the slow log

### Authorization

The dashboard uses a `viewMetrics` gate with IP-based authorization (similar to Laravel Horizon):

- **Local environment** — all access allowed
- **Production** — only IPs listed in `METRICS_ALLOWED_IPS` are allowed
- Denied access is logged

To customize the gate, override it in a service provider:

```php
Gate::define('viewMetrics', function ($user = null) {
    // your custom logic
});
```

### Dashboard Views

Organise the dashboard into tabbed pages with the `views` config. Each view has a `label` and a list of `sections`. When `views` is empty (default), a single page shows the built-in panels, every custom metric as a tile, and the slow log.

```php
// config/metrics.php → dashboard
'periods'        => [15 => '15m', 60 => '1h', 360 => '6h', 1440 => '24h', 10080 => '7d'],
'default_period' => 60,
'refresh'        => 30,                 // seconds, 0 = off
'names'          => ['payment' => 'Payment gateway'],
'status'         => [                   // header pill
    ['label' => 'Error rate', 'value' => 'error_rate', 'warn' => '> 1', 'error' => '> 5'],
],
'views' => [
    [
        'label' => 'Overview',
        'sections' => [
            ['type' => 'stats', 'items' => [
                ['label' => 'Requests', 'value' => 'http_requests_total', 'sub' => '{http_requests_5xx} errors', 'spark' => true],
                ['label' => 'Avg response', 'value' => 'http_avg_duration', 'sub' => 'max {http_max_duration}', 'spark' => true,
                    'status' => ['warn' => '> 500', 'error' => '> 2000', 'ok' => false]],
                ['label' => 'Error rate', 'value' => 'error_rate', 'status' => ['warn' => '> 1', 'error' => '> 5']],
            ]],
            ['type' => 'panel', 'title' => 'Payments', 'caption' => 'calls / min', 'blocks' => [
                ['chart' => ['series' => ['payment_api_calls'],
                    'highlight' => ['value' => 'payment_api_errors', 'when' => '> 0', 'label' => 'has errors']]],
                ['stats' => [
                    ['label' => 'Avg', 'value' => 'payment_api_avg_ms'],
                    ['label' => 'Errors', 'value' => 'payment_api_errors', 'status' => ['warn' => '> 0', 'ok' => false]],
                ]],
            ]],
            ['type' => 'panel', 'title' => 'Containers', 'blocks' => [
                ['table' => [
                    'rows_from' => 'container_*_memory_mb', 'sort' => 'desc',
                    'columns' => [
                        ['label' => 'Container', 'text' => '$'],
                        ['label' => 'Memory', 'value' => 'container_$_memory_mb'],
                        ['label' => '', 'bar' => 'container_$_memory_mb', 'max' => 'container_$_memory_limit_mb'],
                    ],
                ]],
            ]],
            ['type' => 'slow-logs', 'title' => 'Slow log', 'limit' => 10],
        ],
    ],
],
```

#### Values, templates and status rules

| Concept | Syntax |
|---------|--------|
| Metric key | `http_requests_total` (built-in summary fields first, then custom metrics). Built-ins also include `queue_depth` (high + default + low) and `error_rate`. |
| Charts and sparklines | Read the timeline, which has every custom metric plus these built-ins: `http_requests_total`, `http_avg_duration`, `http_max_duration`, `http_requests_5xx`, `http_slow_requests`, `error_rate`, `queue_depth` (and `_high` / `_default` / `_low`), `db_queries_total`, `db_slow_queries`, `cpu_load`, `redis_memory_mb`, `redis_ops_per_sec`. Pattern functions and `rows_from` match custom metrics only. |
| Expression | `+ - * /` and parentheses: `payment_api_errors / payment_api_calls * 100`. Keys may contain `-`, so put spaces around a minus. Division by zero gives 0 when the numerator is 0, otherwise no value. |
| Pattern functions | `sum()`, `avg()`, `min()`, `max()`, `count()` over keys matching a `*` pattern; `argmax(*_api_max_ms)` returns the name of the largest; `breakdown(*_api_errors)` lists non-zero ones (`Payments 2 · Search 1`). Names come from the `names` map. |
| Template | Text fields (`sub`, `caption`, `text`, `note`, chart `title`) accept `{expr}` and `{expr\|format}`, plus `{period}` (selected range label) and `{max}` (chart scale top). |
| Formats | `num` (15.6K), `int`, `dec`, `ms`, `pct`, `ratio` (×100 %), `mb`, `gb`, `mb_as_gb`, `mbps`, `short` (text before the first dot), or `['suffix' => '%', 'decimals' => 1, 'multiply' => 1]`. Without one, the format follows the key name (`_ms`, `_percent`, `_mb`, …). |
| Status rule | `['warn' => '> 0', 'error' => '>= 50', 'value' => 'other_key', 'ok' => false]`. Operators: `< <= > >= == !=`. `value` evaluates another metric; `'ok' => false` keeps healthy values neutral instead of lime. |
| Row placeholder | In `rows_from` tables and meters, `$` is the part matched by `*`: the raw key part inside `{…}` and value expressions, the display name in plain text. |

#### Section Types

| Type | Options |
|------|---------|
| `stats` | `items` — list of items (`label`, `value` or `text`, `format`, `sub`, `spark` (`true` or a series expression), `status`). Or `keys` — patterns for one tile per matching custom metric. `label` adds a heading. |
| `panel` | `title`, `caption`, `status` (marker next to the title), `wide` (span the full row), and either `blocks`, `columns` (list of block lists, side by side) or `figure` (one big value). Consecutive panels share a responsive grid. |
| `slow-logs` | `title`, `caption`, `log_type` (`request` / `query`), `route` (pattern on the route URI, e.g. `api/checkout/*`), `controls` (type/sort buttons and pagination, default `true`), `limit` (rows per page). |
| `built-in` | Built-in tiles and panels. Set `'id'` to show only one tile group: `'http'`, `'queue'`, `'database'`, `'system'`. |
| `cards`, `list`, `chart`, `all-custom-cards` | Original section types, still rendered with the current components: `cards` → `stats` with `keys`, `list` → panel with `ranked`, `chart` → panel with a chart over `keys` patterns (`labels`, `labels_from`, `colors`, `chart_type`, `format`, `value_colors`). |

#### Panel Blocks

| Block | Options |
|-------|---------|
| `chart` | `series` — keys/expressions or `['value', 'label', 'color', 'type']`; `type` `bar` (default; several bar series are grouped), `line` or `stacked`; `height` (px); `highlight` — `['value', 'when', 'label']` paints bars orange where the condition holds; `title`; `legend` (`'top'`, `false`); `legend_note`; `values` (current value in the legend); `axis` (y-axis labels); `format`; `ticks` (x labels). Colours: `lime`, `white`, `blue`, `gray`, `orange`, `red`, `purple`, `cyan`, `pink`, `yellow`, `bar` or a hex value. |
| `stats` | List of items in one row, or `['columns' => 2, 'items' => […]]`. |
| `rows` | Key/value list of items. |
| `meters` | `items` (`label`, `value`, `max` — default 100) or `rows_from` + `value`, `max`, `detail`; `layout` `inline` or `stacked`; `sort`, `limit`. |
| `table` | `columns` (`label`, `value` / `text` / `bar` + `max`, `width`, `align`, `status`), rows from `rows_from` (pattern) or `rows` (fixed list of names), `order`, `sort`, `limit`, `spark` and `note` / `notes` (per row name) under each row, `divider`. |
| `ranked` | `label_keys` + `value_keys` patterns paired by the `*` part (e.g. `top*_domain` with `top*_count`), `max` (default 5), `layout` `stacked` or `compact`. |
| `trends` | Same pairing over the timeline: one mini bar chart per label on a shared scale, ranked by total. Needs the label metrics to be strings. |
| `hero` | One large value with a `caption`. |
| `note` | Muted text template; `['text' => …, 'divider' => true]` adds a rule above. |

Tabs persist across page refreshes (via localStorage) and are linkable via URL hash (e.g. `#infrastructure`). The selected period is remembered too.

## JSON API

Enable with `METRICS_ROUTES_ENABLED=true`. Protected by bearer token (`Authorization: Bearer <token>`).

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
| Mail | `notifications.mail.to` | `METRICS_NOTIFICATION_MAIL_TO` | HTML email with "Open Dashboard" button |
| Slack | `notifications.slack.webhook_url` | `METRICS_NOTIFICATION_SLACK_URL` | Slack message with markdown formatting |
| Discord | `notifications.discord.webhook_url` | `METRICS_NOTIFICATION_DISCORD_URL` | Rich embed with color, fields, timestamp |
| Google Chat | `notifications.google_chat.webhook_url` | `METRICS_NOTIFICATION_GOOGLE_CHAT_URL` | Text message with emoji and dashboard link |

Multiple channels can be used simultaneously.

### Configuration

```env
METRICS_NOTIFICATIONS_ENABLED=true
METRICS_ALERT_INTERVAL=1              # check every N minutes
METRICS_ALERT_COOLDOWN=60             # default cooldown per alert (minutes)

# Comma-separated channel list
METRICS_NOTIFICATION_CHANNELS=discord,google_chat

# Channel-specific webhook URLs
METRICS_NOTIFICATION_DISCORD_URL=https://discord.com/api/webhooks/...
METRICS_NOTIFICATION_GOOGLE_CHAT_URL=https://chat.googleapis.com/v1/spaces/.../messages?key=...&token=...
METRICS_NOTIFICATION_SLACK_URL=https://hooks.slack.com/services/...
METRICS_NOTIFICATION_MAIL_TO=admin@example.com,ops@example.com
```

### Notification Thresholds

Thresholds are evaluated against aggregated data from the configured interval:

| Threshold | Env Variable | Default | Triggers When |
|-----------|-------------|---------|---------------|
| 5xx error rate | `METRICS_NOTIFY_ERROR_RATE` | 0.05 (5%) | Error rate exceeds percentage |
| Queue depth | `METRICS_NOTIFY_QUEUE_DEPTH` | 100 | Total pending jobs exceed count |
| Failed jobs | `METRICS_NOTIFY_FAILED_JOBS` | 10 | Failed jobs exceed count |
| Avg response | `METRICS_NOTIFY_AVG_RESPONSE` | 2000ms | Average response time exceeds threshold |
| Max response | `METRICS_NOTIFY_MAX_RESPONSE` | 10000ms | Max response time exceeds threshold |
| CPU load | `METRICS_NOTIFY_CPU` | 5.0 | 1-minute load average exceeds threshold |
| Disk free | `METRICS_NOTIFY_DISK_GB` | 2.0 GB | Free disk space drops below threshold |
| Redis memory | `METRICS_NOTIFY_REDIS_MB` | 500 MB | Redis memory usage exceeds threshold |
| Slow queries | `METRICS_NOTIFY_SLOW_QUERIES` | 50 | Slow queries exceed count |

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

With batching on (`METRICS_BATCH`, the default), `increment()` and `pushToList()` only record in memory. Everything recorded is sent to Redis in one pipeline after the response has been sent, after each queue job, after each console command, or once `METRICS_BATCH_SIZE` values are waiting - whichever comes first. With request and query tracking on, that is one round trip per request instead of three per request plus two per database query. A key still gets its TTL only when it is created (a second, rare round trip), so values nobody collects do not pile up. Call `flush()` to send earlier; `flushRedisCounters()` does so itself. Values recorded by a request that ends in a fatal error are lost.

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

All tables support a separate database connection via `METRICS_DB_CONNECTION`.

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
