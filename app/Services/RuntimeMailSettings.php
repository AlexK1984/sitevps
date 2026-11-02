<?php

namespace App\Services;

use App\Models\PaymentSettings;
use Illuminate\Support\Facades\Mail;

class RuntimeMailSettings
{
    private const ALLOWED_TRANSPORTS = ['smtp', 'sendmail', 'mailgun', 'ses', 'ses-v2', 'postmark', 'resend'];

    public function configure(): bool
    {
        $settings = PaymentSettings::current();

        if ($settings->smtp_enabled && ! $settings->smtpConfiguredInAdmin()) {
            return false;
        }

        if ($settings->smtpConfiguredInAdmin()) {
            $currentMailer = (string) config('mail.default');
            Mail::purge($currentMailer);
            Mail::purge('smtp');

            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp' => [
                    'transport' => 'smtp',
                    'scheme' => match ($settings->smtp_encryption) {
                        'ssl' => 'smtps',
                        default => 'smtp',
                    },
                    'url' => null,
                    'host' => $settings->smtp_host,
                    'port' => $settings->smtp_port,
                    'username' => $settings->smtp_username,
                    'password' => $settings->smtp_password,
                    'timeout' => 15,
                    'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST),
                ],
                'mail.from.address' => $settings->smtp_from_address,
                'mail.from.name' => $settings->smtp_from_name ?: config('app.name'),
            ]);
        }

        $mailer = config('mail.default');
        $transport = config("mail.mailers.{$mailer}.transport");

        return in_array($transport, self::ALLOWED_TRANSPORTS, true)
            && filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false;
    }
}
