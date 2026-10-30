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
        } catch (ConnectionException $exception) {
            throw $this->connectionFailure($exception, 'получить CSRF-токен');
        }

        $csrfToken = null;
        if ($csrf->status() !== 404 && $csrf->status() !== 405) {
            $csrfPayload = $this->successfulApiResponse($csrf, 'получить CSRF-токен');
            if (! is_string($csrfPayload['obj'] ?? null) || $csrfPayload['obj'] === '') {
                throw new PanelApiException('Панель 3x-ui вернула некорректный ответ при получении CSRF-токена.');
            }
            $csrfToken = $csrfPayload['obj'];
        }

        try {
            $login = $this->login($panel, $cookies, $csrfToken, followRedirects: false);
        } catch (ConnectionException $exception) {
            throw $this->connectionFailure($exception, 'войти в панель');
        }
        $this->successfulApiResponse($login, 'войти в панель');

        try {
            $inbounds = Http::withOptions(['cookies' => $cookies])
                ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
                ->acceptJson()
                ->withoutRedirecting()
                ->timeout(15)
                ->get($baseUrl.'/panel/api/inbounds/list');
        } catch (ConnectionException $exception) {
            throw $this->connectionFailure($exception, 'получить список inbound');
        }
        $inboundsPayload = $this->successfulApiResponse($inbounds, 'получить список inbound');
        if (! is_array($inboundsPayload['obj'] ?? null)) {
            throw new PanelApiException('Панель 3x-ui вернула некорректный список inbound.');
        }
        $inbound = collect($inboundsPayload['obj'] ?? [])
            ->first(fn ($item) => (int) data_get($item, 'id') === (int) $panel->inbound_id);

        if (! is_array($inbound)) {
            throw new PanelApiException('Настроенный inbound не найден в панели 3x-ui.');
        }

        $inboundSettings = data_get($inbound, 'settings');
        if (is_string($inboundSettings)) {
            $inboundSettings = json_decode($inboundSettings, true);
        }
        if (! is_array($inboundSettings) || ! is_array($inboundSettings['clients'] ?? null)) {
            throw new PanelApiException('Панель 3x-ui вернула некорректные настройки выбранного inbound.');
        }

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

        try {
            $headers = ['X-Requested-With' => 'XMLHttpRequest'];
            if ($csrfToken !== null) {
                $headers['X-CSRF-Token'] = $csrfToken;
            }

            $response = Http::withOptions(['cookies' => $cookies])
                ->withHeaders($headers)
                ->acceptJson()
                ->asJson()
                ->withoutRedirecting()
                ->timeout(15)
                ->post($baseUrl.'/panel/api/clients/add', [
                    'client' => $client,
                    'inboundIds' => [(int) $panel->inbound_id],
                ]);
        } catch (ConnectionException $exception) {
            throw $this->connectionFailure($exception, 'создать или обновить клиента');
        }

        $this->successfulApiResponse($response, 'создать или обновить клиента');
    }

    private function successfulApiResponse(Response $response, string $stage): array
    {
        $status = $response->status();

        if ($response->redirect()) {
            throw new PanelApiException("Ответ панели 3x-ui перенаправлен на этапе «{$stage}» (HTTP {$status}); проверьте адрес панели и сессию.");
        }
        if ($status === 401 || $status === 403) {
            throw new PanelApiException("Панель 3x-ui отклонила авторизацию или сессию на этапе «{$stage}» (HTTP {$status}).");
        }
        if ($status === 404 || $status === 405) {
            throw new PanelApiException("Маршрут API панели 3x-ui не найден или недоступен на этапе «{$stage}» (HTTP {$status}); проверьте base path и совместимость версии.");
        }
        if (! $response->successful()) {
            throw new PanelApiException("Панель 3x-ui вернула HTTP {$status} на этапе «{$stage}».");
        }
        if ($this->isHtmlResponse($response)) {
            throw new PanelApiException("Панель 3x-ui вернула HTML вместо ответа API на этапе «{$stage}» (HTTP {$status}); проверьте base path.");
        }

        $payload = $response->json();
        if (! is_array($payload) || ! array_key_exists('success', $payload)) {
            throw new PanelApiException("Панель 3x-ui вернула некорректный ответ API на этапе «{$stage}» (HTTP {$status}).");
        }
        if ($payload['success'] !== true) {
            throw new PanelApiException("Панель 3x-ui отклонила запрос на этапе «{$stage}» (HTTP {$status}); ответ не содержит подробностей во избежание записи чувствительных данных.");
        }

        return $payload;
    }

    private function connectionFailure(ConnectionException $exception, string $stage): PanelApiException
    {
        $category = $this->classifyConnectionException($exception);
        $description = match ($category) {
            'dns_error' => 'ошибка DNS',
            'connect_timeout' => 'истекло время ожидания соединения',
            'tls_error' => 'ошибка проверки TLS-сертификата',
            'connection_refused' => 'соединение отклонено',
            default => 'сетевая ошибка',
        };

        return new PanelApiException("Не удалось {$stage}: {$description}.");
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
