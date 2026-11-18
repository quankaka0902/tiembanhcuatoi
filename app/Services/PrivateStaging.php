<?php

namespace App\Services;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mailer\Envelope;

class PrivateStaging
{
    public static function enabled(): bool
    {
        return app()->environment('staging');
    }

    public static function host(): string
    {
        return strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
    }

    public static function accessConfigured(): bool
    {
        $url = (string) config('app.url');
        $parts = parse_url($url);

        return filter_var($url, FILTER_VALIDATE_URL) !== false && ($parts['scheme'] ?? null) === 'https'
            && ! isset($parts['user']) && ! isset($parts['query']) && ! isset($parts['fragment'])
            && in_array($parts['path'] ?? '', ['', '/'], true)
            && ! LaunchReadiness::loopback(self::host())
            && strlen((string) config('bakery.staging.password')) >= 32;
    }

    public static function isolatedDatabase(): bool
    {
        return config('database.default') === 'mysql'
            && str_ends_with(DB::connection()->getDatabaseName(), '_test');
    }

    public static function smtpConfigured(): bool
    {
        $smtp = config('mail.mailers.smtp');

        return config('mail.default') === 'smtp' && $smtp['transport'] === 'smtp'
            && filled($smtp['host']) && ! $smtp['url'] && $smtp['require_tls']
            && in_array($smtp['scheme'], [null, 'smtp', 'smtps'], true);
    }

    public static function approvedRecipients(): array
    {
        $recipients = config('bakery.staging.recipients');
        foreach ($recipients as $address) {
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                return [];
            }
        }

        return $recipients;
    }

    public function checkOutgoingMail(MessageSending $event): ?bool
    {
        if (! self::enabled()) {
            return null;
        }

        $approved = self::approvedRecipients();
        if (! $approved || ! self::smtpConfigured() || ($event->data['mailer'] ?? null) !== 'smtp') {
            return false;
        }
        $recipients = Envelope::create($event->message)->getRecipients();
        foreach ($recipients as $recipient) {
            if (! in_array($recipient->getAddress(), $approved, true)) {
                return false;
            }
        }

        return null;
    }
}
