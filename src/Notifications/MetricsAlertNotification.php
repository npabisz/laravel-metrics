<?php

namespace Npabisz\LaravelMetrics\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Npabisz\LaravelMetrics\Notifications\Channels\DiscordChannel;
use Npabisz\LaravelMetrics\Notifications\Channels\GoogleChatChannel;

class MetricsAlertNotification extends Notification
{
    use Queueable;

    protected array $alerts;
    protected array $summary;

    /**
     * Channel class mapping for custom channels.
     */
    protected static array $channelMap = [
        'discord'     => DiscordChannel::class,
        'google_chat' => GoogleChatChannel::class,
    ];

    public function __construct(array $alerts, array $summary)
    {
        $this->alerts = $alerts;
        $this->summary = $summary;
    }

    public function via($notifiable): array
    {
        $channels = config('metrics.notifications.channels', ['mail']);

        return array_map(function ($channel) {
            return static::$channelMap[$channel] ?? $channel;
        }, $channels);
    }

    // ─── Mail ───────────────────────────────────────────────

    public function toMail($notifiable): MailMessage
    {
        $appName = $this->getAppName();
        $alertCount = count($this->alerts);

        $message = (new MailMessage)
            ->subject("[{$appName}] Monitoring Alert - {$alertCount} issue(s) detected")
            ->greeting("Monitoring Alert")
            ->line("**{$alertCount} issue(s)** detected in the last 15 minutes on **{$appName}**.");

        foreach ($this->alerts as $alert) {
            $icon = $alert['severity'] === 'critical' ? '!!' : '!';
            $message->line("[{$icon}] **{$alert['label']}**: {$alert['message']}");
        }

        $message->line('---');
        $message->line('**Summary (last 15 min):**');

        foreach ($this->formatSummaryLines() as $line) {
            $message->line($line);
        }

        if ($this->shouldIncludeDashboardUrl()) {
            $message->action('Open Dashboard', $this->getDashboardUrl());
        }

        return $message;
    }

    // ─── Slack ──────────────────────────────────────────────

    public function toSlack($notifiable)
    {
        // SlackMessage may be in illuminate/notifications (L9/10) or laravel/slack-notification-channel (L11+)
        $slackMessageClass = class_exists(\Illuminate\Notifications\Messages\SlackMessage::class)
            ? \Illuminate\Notifications\Messages\SlackMessage::class
            : null;

        if (!$slackMessageClass) {
            return null;
        }

        $appName = $this->getAppName();
        $alertCount = count($this->alerts);

        $hasCritical = collect($this->alerts)->contains('severity', 'critical');
        $emoji = $hasCritical ? ':rotating_light:' : ':warning:';

        $alertLines = collect($this->alerts)->map(function ($alert) {
            $icon = $alert['severity'] === 'critical' ? ':red_circle:' : ':large_orange_circle:';
            return "{$icon} *{$alert['label']}*: {$alert['message']}";
        })->implode("\n");

        $summaryText = implode(' | ', $this->formatSummaryCompact());

        $content = "{$emoji} *[{$appName}] {$alertCount} monitoring issue(s)*\n\n{$alertLines}\n\n_{$summaryText}_";

        if ($this->shouldIncludeDashboardUrl()) {
            $content .= "\n\n<{$this->getDashboardUrl()}|Open Dashboard>";
        }

        return (new $slackMessageClass)
            ->warning()
            ->content($content);
    }

    // ─── Discord ────────────────────────────────────────────

    public function toDiscord($notifiable): array
    {
        $appName = $this->getAppName();

        $hasCritical = collect($this->alerts)->contains('severity', 'critical');
        $color = $hasCritical ? 0xFF0000 : 0xFFA500;

        $alertLines = collect($this->alerts)->map(function ($alert) {
            $icon = $alert['severity'] === 'critical' ? "\u{1F534}" : "\u{1F7E0}";
            return "{$icon} **{$alert['label']}**: {$alert['message']}";
        })->implode("\n");

        $summaryText = implode("\n", $this->formatSummaryLines());

        $fields = [
            [
                'name'  => 'Summary (last 15 min)',
                'value' => $summaryText,
            ],
        ];

        if ($this->shouldIncludeDashboardUrl()) {
            $fields[] = [
                'name'  => 'Dashboard',
                'value' => "[Open Dashboard]({$this->getDashboardUrl()})",
            ];
        }

        return [
            'embeds' => [
                [
                    'title'       => "[{$appName}] Monitoring Alert",
                    'description' => $alertLines,
                    'color'       => $color,
                    'fields'      => $fields,
                    'timestamp'   => now()->toIso8601String(),
                ],
            ],
        ];
    }

    // ─── Google Chat ────────────────────────────────────────

    public function toGoogleChat($notifiable): array
    {
        $appName = $this->getAppName();
        $alertCount = count($this->alerts);

        $hasCritical = collect($this->alerts)->contains('severity', 'critical');
        $emoji = $hasCritical ? "\u{1F6A8}" : "\u{26A0}\u{FE0F}";

        $alertLines = collect($this->alerts)->map(function ($alert) {
            $icon = $alert['severity'] === 'critical' ? "\u{1F534}" : "\u{1F7E0}";
            return "{$icon} *{$alert['label']}*: {$alert['message']}";
        })->implode("\n");

        $summaryText = implode(' | ', $this->formatSummaryCompact());

        $text = "{$emoji} *{$appName} -> {$alertCount} monitoring issue(s)*\n\n"
            . "{$alertLines}\n\n"
            . "_{$summaryText}_";

        if ($this->shouldIncludeDashboardUrl()) {
            $text .= "\n\n<{$this->getDashboardUrl()}|Open Dashboard>";
        }

        return [
            'text' => $text,
        ];
    }

    // ─── Array ──────────────────────────────────────────────

    public function toArray($notifiable): array
    {
        return [
            'alerts'  => $this->alerts,
            'summary' => $this->summary,
        ];
    }

    // ─── Helpers ────────────────────────────────────────────

    protected function shouldIncludeDashboardUrl(): bool
    {
        return (bool) config('metrics.notifications.include_dashboard_url', true);
    }

    protected function getDashboardUrl(): string
    {
        return url(config('metrics.dashboard.path', 'monitoring'));
    }

    protected function getAppName(): string
    {
        if ($custom = config('metrics.notifications.app_name')) {
            return $custom;
        }

        $name = config('app.name', 'Laravel');
        $env = app()->environment();

        if ($env && $env !== 'production') {
            $name .= ' [' . $env . ']';
        }

        return $name;
    }

    protected function formatSummaryLines(): array
    {
        $s = $this->summary;
        $lines = [];

        if (isset($s['http_requests_total'])) {
            $lines[] = "HTTP: {$s['http_requests_total']} requests, avg {$s['http_avg_duration_ms']}ms, {$s['http_requests_5xx']} errors (5xx)";
        }

        if (isset($s['queue_depth_total'])) {
            $lines[] = "Queue depth: {$s['queue_depth_total']} (H:{$s['queue_depth_high']} D:{$s['queue_depth_default']} L:{$s['queue_depth_low']})";
        }

        if (isset($s['db_slow_queries'])) {
            $lines[] = "DB: {$s['db_queries_total']} queries, {$s['db_slow_queries']} slow";
        }

        if (isset($s['cpu_load_1m'])) {
            $lines[] = "CPU: {$s['cpu_load_1m']} | Redis: " . ($s['redis_memory_used_mb'] ?? 'N/A') . "MB | Disk: " . ($s['disk_free_gb'] ?? 'N/A') . "GB free";
        }

        return $lines;
    }

    protected function formatSummaryCompact(): array
    {
        $s = $this->summary;
        $parts = [];

        if (isset($s['http_requests_total'])) {
            $parts[] = "HTTP: {$s['http_requests_total']} req";
        }
        if (isset($s['http_requests_5xx']) && $s['http_requests_5xx'] > 0) {
            $parts[] = "5xx: {$s['http_requests_5xx']}";
        }
        if (isset($s['queue_depth_total'])) {
            $parts[] = "Queue: {$s['queue_depth_total']}";
        }
        if (isset($s['cpu_load_1m'])) {
            $parts[] = "CPU: {$s['cpu_load_1m']}";
        }

        return $parts;
    }
}
