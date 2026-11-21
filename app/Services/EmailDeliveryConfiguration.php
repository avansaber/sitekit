<?php

namespace App\Services;

use Illuminate\Support\ConfigurationUrlParser;

class EmailDeliveryConfiguration
{
    public function isConfigured(): bool
    {
        return $this->mailerIsConfigured(config('mail.default'));
    }

    public function unavailableMessage(): string
    {
        return __('Email verification is currently unavailable because email delivery has not been configured. Please contact the site administrator. Your account will remain unverified until email delivery is configured.');
    }

    /**
     * @param  array<string>  $visited
     */
    private function mailerIsConfigured(?string $name, array $visited = []): bool
    {
        if (! $name || in_array($name, $visited, true)) {
            return false;
        }

        $config = config("mail.mailers.{$name}");

        if (! is_array($config)) {
            return false;
        }

        $config = (new ConfigurationUrlParser)->parseConfiguration($config);
        $transport = $config['transport'] ?? null;

        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            foreach ($config['mailers'] ?? [] as $mailer) {
                if ($this->mailerIsConfigured($mailer, [...$visited, $name])) {
                    return true;
                }
            }

            return false;
        }

        return match ($transport) {
            null, 'log', 'array' => false,
            'smtp' => filled($config['host'] ?? null),
            'postmark' => filled($config['token'] ?? config('services.postmark.token', config('services.postmark.key'))),
            'resend' => filled($config['key'] ?? config('services.resend.key')),
            'mailgun' => filled($config['secret'] ?? config('services.mailgun.secret'))
                && filled($config['domain'] ?? config('services.mailgun.domain')),
            default => true,
        };
    }
}
