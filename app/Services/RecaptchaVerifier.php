<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class RecaptchaVerifier
{
    public function verify(string $token, ?string $ipAddress): bool
    {
        $secret = config('services.recaptcha.secret_key');
        $hostname = config('services.recaptcha.hostname')
            ?: parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($secret) || $secret === '' || ! is_string($hostname) || $hostname === '') {
            return false;
        }

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(5)
                ->post(config('services.recaptcha.verify_url'), array_filter([
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $ipAddress,
                ], fn (mixed $value): bool => $value !== null));
        } catch (ConnectionException) {
            return false;
        }

        $payload = $response->json();

        $verified = $response->successful()
            && is_array($payload)
            && ($payload['success'] ?? false) === true
            && is_string($payload['hostname'] ?? null)
            && hash_equals(strtolower($hostname), strtolower($payload['hostname']));

        if (! $verified) {
            return false;
        }

        return Cache::add(
            'recaptcha:consumed:'.hash('sha256', $token),
            true,
            now()->addMinutes(2),
        );
    }
}
