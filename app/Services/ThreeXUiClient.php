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
        $csrfToken = null;

        try {
            $csrf = $this->csrfToken($panel, $cookies, followRedirects: false);
        } catch (ConnectionException $exception) {
            return new PanelConnectionResult(false, $this->classifyConnectionException($exception));
        }

        if ($csrf->redirect()) {
            return new PanelConnectionResult(false, 'csrf_redirect', $csrf->status());
        }
        if ($csrf->status() === 401 || $csrf->status() === 403) {
            return new PanelConnectionResult(false, $csrf->status() === 401 ? 'csrf_unauthorized' : 'csrf_forbidden', $csrf->status());
        }
        if ($csrf->status() !== 404 && $csrf->status() !== 405) {
            if (! $csrf->successful()) {
                return new PanelConnectionResult(false, $this->classifyHttpStatus($csrf->status()), $csrf->status());
            }
            if ($this->isHtmlResponse($csrf)) {
                return new PanelConnectionResult(false, 'csrf_html', $csrf->status());
            }

            $csrfPayload = $csrf->json();
            if (! is_array($csrfPayload) || ($csrfPayload['success'] ?? null) !== true
                || ! is_string($csrfPayload['obj'] ?? null) || $csrfPayload['obj'] === '') {
                return new PanelConnectionResult(false, 'csrf_malformed', $csrf->status());
            }
            $csrfToken = $csrfPayload['obj'];
        }

        try {
            $login = $this->login($panel, $cookies, $csrfToken, followRedirects: false);
        } catch (ConnectionException $exception) {
            return new PanelConnectionResult(false, $this->classifyConnectionException($exception));
        }

        if ($login->redirect()) {
            return new PanelConnectionResult(false, 'login_redirect', $login->status());
        }
        if ($login->status() === 404 || $login->status() === 405) {
            return new PanelConnectionResult(false, 'login_endpoint_not_found', $login->status());
        }
        if ($login->status() === 401) {
            return new PanelConnectionResult(false, 'login_unauthorized', $login->status());
        }
        if ($login->status() === 403) {
            return new PanelConnectionResult(false, 'login_forbidden', $login->status());
        }
        if (! $login->successful()) {
            return new PanelConnectionResult(false, $this->classifyHttpStatus($login->status()), $login->status());
        }
        if ($this->isHtmlResponse($login)) {
            return new PanelConnectionResult(false, 'login_html', $login->status());
        }
        $loginPayload = $login->json();
        if (! is_array($loginPayload) || ! array_key_exists('success', $loginPayload)) {
            return new PanelConnectionResult(false, 'login_malformed', $login->status());
        }
        if ($loginPayload['success'] !== true) {
            return new PanelConnectionResult(false, 'login_rejected', $login->status());
        }

        try {
            $inbounds = Http::withOptions(['cookies' => $cookies])
                ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
                ->acceptJson()
                ->withoutRedirecting()
                ->timeout(15)
                ->get($baseUrl.'/panel/api/inbounds/list');
        } catch (ConnectionException $exception) {
            return new PanelConnectionResult(false, $this->classifyConnectionException($exception));
        }

        if ($inbounds->redirect()) {
            return new PanelConnectionResult(false, 'api_redirect', $inbounds->status());
        }
        if ($inbounds->status() === 404 || $inbounds->status() === 405) {
            return new PanelConnectionResult(false, 'api_endpoint_not_found', $inbounds->status());
        }
        if ($inbounds->status() === 401) {
            return new PanelConnectionResult(false, 'api_unauthorized', $inbounds->status());
        }
        if ($inbounds->status() === 403) {
            return new PanelConnectionResult(false, 'api_forbidden', $inbounds->status());
        }
        if (! $inbounds->successful()) {
            return new PanelConnectionResult(false, $this->classifyHttpStatus($inbounds->status()), $inbounds->status());
        }
        if ($this->isHtmlResponse($inbounds)) {
            return new PanelConnectionResult(false, 'api_html', $inbounds->status());
        }
        $inboundsPayload = $inbounds->json();
        if (! is_array($inboundsPayload) || ($inboundsPayload['success'] ?? null) !== true
            || ! is_array($inboundsPayload['obj'] ?? null)) {
            return new PanelConnectionResult(false, 'api_malformed', $inbounds->status());
        }

        $hasInbound = collect($inboundsPayload['obj'])
            ->contains(fn ($item) => (int) data_get($item, 'id') === (int) $panel->inbound_id);

        return new PanelConnectionResult($hasInbound, $hasInbound ? 'ok' : 'inbound_not_found', $inbounds->status());
    }

    private function isHtmlResponse(Response $response): bool
    {
        return str_contains(strtolower($response->header('Content-Type', '')), 'text/html');
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
            $csrf = $this->csrfToken($panel, $cookies, followRedirects: false);
            $csrfToken = null;
            if ($csrf->status() !== 404 && $csrf->status() !== 405) {
                $csrfPayload = $csrf->json();
                if (! $csrf->successful() || ! is_array($csrfPayload)
                    || ($csrfPayload['success'] ?? null) !== true
                    || ! is_string($csrfPayload['obj'] ?? null) || $csrfPayload['obj'] === '') {
                    throw new PanelApiException('Не удалось получить CSRF-токен панели 3x-ui.');
                }
                $csrfToken = $csrfPayload['obj'];
            }

            $login = $this->login($panel, $cookies, $csrfToken, followRedirects: false);

            if (! $login->successful() || $login->json('success') !== true) {
                throw new PanelApiException('Не удалось войти в панель 3x-ui.');
            }

            $inbounds = Http::withOptions(['cookies' => $cookies])
                ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
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
                ->withHeaders($csrfToken !== null ? ['X-CSRF-Token' => $csrfToken] : [])
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

    private function csrfToken(Panel $panel, CookieJar $cookies, bool $followRedirects): Response
    {
        $request = Http::withOptions(['cookies' => $cookies])
            ->acceptJson()
            ->timeout(15);

        if (! $followRedirects) {
            $request = $request->withoutRedirecting();
        }

        return $request->get(rtrim($panel->base_url, '/').'/csrf-token');
    }

    private function login(
        Panel $panel,
        CookieJar $cookies,
        ?string $csrfToken = null,
        bool $followRedirects = true,
    ): Response {
        $request = Http::withOptions(['cookies' => $cookies])
            ->timeout(15)
            ->asForm();

        if ($csrfToken !== null) {
            $request = $request->withHeaders(['X-CSRF-Token' => $csrfToken]);
        }
        if (! $followRedirects) {
            $request = $request->withoutRedirecting();
        }

        return $request->post(rtrim($panel->base_url, '/').'/login', [
            'username' => $panel->username,
            'password' => $panel->password,
        ]);
    }
}
