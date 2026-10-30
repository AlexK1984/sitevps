<?php

namespace App\Services;

use App\Exceptions\PanelApiException;
use App\Models\Panel;
use App\Models\Subscription;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class ThreeXUiClient
{
    public function provision(Panel $panel, Subscription $subscription): void
    {
        $cookies = new CookieJar;
        $baseUrl = rtrim($panel->base_url, '/');

        try {
            $login = Http::withOptions(['cookies' => $cookies])
                ->asForm()
                ->timeout(15)
                ->post($baseUrl.'/login', [
                    'username' => $panel->username,
                    'password' => $panel->password,
                ]);

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
}
