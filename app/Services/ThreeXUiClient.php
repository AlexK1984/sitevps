<?php

namespace App\Services;

use App\Exceptions\PanelApiException;
use App\Models\Panel;
use App\Models\Subscription;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class ThreeXUiClient
{
    public function testConnection(Panel $panel): PanelConnectionResult
    {
        $cookies = new CookieJar;
        $baseUrl = rtrim($panel->base_url, '/');

        try {
            $login = $this->login($panel, $cookies);
        } catch (ConnectionException $exception) {
            return new PanelConnectionResult(false, $this->classifyConnectionException($exception));
        }

        if ($login->status() === 401 || $login->status() === 403
            || ($login->successful() && $login->json('success') === false)) {
            return new PanelConnectionResult(false, 'auth_failed');
        }
        if ($login->status() === 404 || $login->status() === 405) {
            return new PanelConnectionResult(false, 'login_endpoint_not_found');
        }
        if (! $login->successful()) {
            return new PanelConnectionResult(false, $this->classifyHttpStatus($login->status()));
        }
        if ($login->json('success') !== true) {
            return new PanelConnectionResult(false, 'unsupported_api');
        }

        try {
            $inbounds = Http::withOptions(['cookies' => $cookies])
                ->acceptJson()
                ->timeout(15)
                ->get($baseUrl.'/panel/api/inbounds/list');
        } catch (ConnectionException $exception) {
            return new PanelConnectionResult(false, $this->classifyConnectionException($exception));
        }

        if ($inbounds->status() === 401 || $inbounds->status() === 403) {
            return new PanelConnectionResult(false, 'auth_failed');
        }
        if ($inbounds->status() === 404 || $inbounds->status() === 405) {
            return new PanelConnectionResult(false, 'api_endpoint_not_found');
        }
        if (! $inbounds->successful()) {
            return new PanelConnectionResult(false, $this->classifyHttpStatus($inbounds->status()));
        }
        if ($inbounds->json('success') !== true || ! is_array($inbounds->json('obj'))) {
            return new PanelConnectionResult(false, 'unsupported_api');
        }

        $hasInbound = collect($inbounds->json('obj'))
            ->contains(fn ($item) => (int) data_get($item, 'id') === (int) $panel->inbound_id);

        return new PanelConnectionResult($hasInbound, $hasInbound ? 'ok' : 'inbound_not_found');
    }

    private function classifyHttpStatus(int $status): string
    {
        return $status >= 500 ? 'server_error' : 'http_error';
    }

    private function classifyConnectionException(ConnectionException $exception): string
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'ssl certificate')
            || str_contains($message, 'certificate verify')
            || str_contains($message, 'tls')
            || str_contains($message, 'curl error 35')
            || str_contains($message, 'curl error 60')) {
            return 'tls_error';
        }
        if (str_contains($message, 'could not resolve')
            || str_contains($message, 'name or service not known')
            || str_contains($message, 'getaddrinfo')
            || str_contains($message, 'curl error 6')) {
            return 'dns_error';
        }
        if (str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'curl error 28')) {
            return 'connect_timeout';
        }
        if (str_contains($message, 'connection refused')
            || str_contains($message, 'curl error 7')) {
            return 'connection_refused';
        }

        return 'network_error';
    }

    public function provision(Panel $panel, Subscription $subscription): void
    {
        $cookies = new CookieJar;
        $baseUrl = rtrim($panel->base_url, '/');

        try {
            $login = $this->login($panel, $cookies);

            if (! $login->successful()) {
                throw new PanelApiException('Не удалось войти в панель 3x-ui.');
            }

            $inbounds = Http::withOptions(['cookies' => $cookies])
                ->acceptJson()
                ->timeout(15)
                ->get($baseUrl.'/panel/api/inbounds/list');

            $inbound = collect($inbounds->json('obj', []))
                ->first(fn ($item) => (int) data_get($item, 'id') === (int) $panel->inbound_id);

            if (! $inbounds->successful() || ! $inbounds->json('success') || ! is_array($inbound)) {
                throw new PanelApiException('Выбранный inbound не найден в панели 3x-ui.');
            }
        } catch (ConnectionException $exception) {
            throw new PanelApiException('Сайт не смог подключиться к панели 3x-ui.', previous: $exception);
        }

        $clients = json_decode((string) data_get($inbound, 'settings', '{}'), true);
        $exists = collect(is_array($clients) ? ($clients['clients'] ?? []) : [])
            ->contains(fn ($client) => data_get($client, 'id') === $subscription->client_uuid);

        $client = [
            'id' => $subscription->client_uuid,
            'subId' => $subscription->sub_id,
            'email' => $subscription->client_email,
            'enable' => true,
            'limitIp' => $subscription->connection_limit,
            'totalGB' => $subscription->traffic_limit_bytes,
            'expiryTime' => $subscription->expires_at?->getTimestampMs() ?? 0,
            'flow' => $panel->flow ?? '',
            'reset' => 0,
        ];

        $endpoint = $exists
            ? '/panel/api/inbounds/updateClient/'.rawurlencode($subscription->client_uuid)
            : '/panel/api/inbounds/addClient';

        try {
            $response = Http::withOptions(['cookies' => $cookies])
                ->acceptJson()
                ->asJson()
                ->timeout(15)
                ->post($baseUrl.$endpoint, [
                    'id' => $panel->inbound_id,
                    'settings' => json_encode(['clients' => [$client]], JSON_THROW_ON_ERROR),
                ]);
        } catch (ConnectionException $exception) {
            throw new PanelApiException('Запрос к панели 3x-ui завершился ошибкой соединения.', previous: $exception);
        }

        if (! $response->successful() || ! $response->json('success')) {
            throw new PanelApiException('Панель 3x-ui не приняла параметры подписки.');
        }
    }

    private function login(Panel $panel, CookieJar $cookies): Response
    {
        return Http::withOptions(['cookies' => $cookies])
            ->asForm()
            ->timeout(15)
            ->post(rtrim($panel->base_url, '/').'/login', [
                'username' => $panel->username,
                'password' => $panel->password,
            ]);
    }
}
