<?php

namespace App\Services;

use App\Exceptions\PaymentGatewayException;
use App\Models\Order;
use App\Models\PaymentSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class YooKassaClient
{
    public function configured(): bool
    {
        $credentials = PaymentSettings::current()->yookassaCredentials();

        return filled($credentials['shop_id']) && filled($credentials['secret_key']);
    }

    public function createPayment(Order $order, string $returnUrl): array
    {
        if (! $this->configured()) {
            throw new PaymentGatewayException('ЮKassa не настроена.');
        }

        $credentials = PaymentSettings::current()->yookassaCredentials();

        try {
            $payload = [
                'amount' => [
                    'value' => number_format($order->amount_kopecks / 100, 2, '.', ''),
                    'currency' => 'RUB',
                ],
                'capture' => true,
                'confirmation' => [
                    'type' => 'redirect',
                    'return_url' => $returnUrl,
                ],
                'description' => 'VPN: '.$order->tariff->name,
                'metadata' => ['order_reference' => $order->reference],
            ];

            if (filled(config('services.yookassa.receipt_vat_code'))) {
                $vatCode = config('services.yookassa.receipt_vat_code');

                if (! ctype_digit((string) $vatCode)) {
                    throw new PaymentGatewayException('Код НДС в настройках ЮKassa должен быть числом.');
                }

                $receipt = [
                    'customer' => ['email' => $order->user->email],
                    'items' => [[
                        'description' => 'VPN-подписка: '.$order->tariff->name,
                        'quantity' => '1.00',
                        'amount' => $payload['amount'],
                        'vat_code' => (int) $vatCode,
                        'payment_mode' => 'full_payment',
                        'payment_subject' => 'service',
                    ]],
                ];

                if (filled(config('services.yookassa.tax_system_code'))) {
                    $taxSystemCode = config('services.yookassa.tax_system_code');

                    if (! ctype_digit((string) $taxSystemCode)) {
                        throw new PaymentGatewayException('Код системы налогообложения в настройках ЮKassa должен быть числом.');
                    }

                    $receipt['tax_system_code'] = (int) $taxSystemCode;
                }

                $payload['receipt'] = $receipt;
            }

            $response = Http::withBasicAuth($credentials['shop_id'], $credentials['secret_key'])
                ->acceptJson()
                ->asJson()
                ->withHeaders(['Idempotence-Key' => $order->reference])
                ->timeout(15)
                ->post('https://api.yookassa.ru/v3/payments', $payload);
        } catch (ConnectionException $exception) {
            throw new PaymentGatewayException('Не удалось подключиться к ЮKassa.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new PaymentGatewayException('Не удалось создать платёж в ЮKassa.');
        }

        $payload = $response->json();
        $paymentId = data_get($payload, 'id');
        $paymentUrl = data_get($payload, 'confirmation.confirmation_url');

        if (! is_string($paymentId) || ! is_string($paymentUrl) || ! str_starts_with($paymentUrl, 'https://')) {
            throw new PaymentGatewayException('ЮKassa вернула некорректные данные платежа.');
        }

        return ['id' => $paymentId, 'url' => $paymentUrl];
    }

    public function getPayment(string $paymentId): array
    {
        if (! $this->configured()) {
            throw new PaymentGatewayException('ЮKassa не настроена.');
        }

        $credentials = PaymentSettings::current()->yookassaCredentials();

        try {
            $response = Http::withBasicAuth($credentials['shop_id'], $credentials['secret_key'])
                ->acceptJson()
                ->timeout(15)
                ->get('https://api.yookassa.ru/v3/payments/'.rawurlencode($paymentId));
        } catch (ConnectionException $exception) {
            throw new PaymentGatewayException('Не удалось подключиться к ЮKassa.', previous: $exception);
        }

        if (! $response->successful() || ! is_array($response->json())) {
            throw new PaymentGatewayException('Не удалось проверить платёж в ЮKassa.');
        }

        return $response->json();
    }
}
