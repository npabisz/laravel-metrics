<?php

namespace Npabisz\LaravelMetrics\Notifications;

use Illuminate\Notifications\Notifiable;

/**
 * Anonymous notifiable for sending monitoring alerts
 * without requiring a User model.
 */
class MetricsAlertNotifiable
{
    use Notifiable;

    public function routeNotificationForMail(): array|string
    {
        $recipients = config('metrics.notifications.mail.to', []);

        if (is_string($recipients)) {
            return array_map('trim', explode(',', $recipients));
        }

        return $recipients;
    }

    public function routeNotificationForSlack(): ?string
    {
        return config('metrics.notifications.slack.webhook_url');
    }

    public function routeNotificationForDiscord(): ?string
    {
        return config('metrics.notifications.discord.webhook_url');
    }

    public function routeNotificationForGoogleChat(): ?string
    {
        return config('metrics.notifications.google_chat.webhook_url');
    }
}
