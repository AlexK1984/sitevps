<?php

namespace App\Services;

use App\Exceptions\PaymentGatewayException;
use App\Models\Order;
use App\Models\PaymentSettings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class NowPaymentsClient
{
    private const API_URL = 'https://api.nowpayments.io/v1';

    public function configured(): bool
    {
        return PaymentSettings::current()->nowpaymentsConfiguredInAdmin();
    }

    public function verifyRubleSupport(): void
    {
        $response = $this->request()
            ->get(self::API_URL.'/min-amount', ['currency_from' => 'rub']);

        $data = $response->json();
        if (! $response->successful()
            || ! is_array($data)
            || ! is_numeric($data['min_amount'] ?? null)
            || (float) $data['min_amount'] <= 0) {
            throw new PaymentGatewayException('NOWPayments could not verify RUB support.');
        }
    }

    /**
     * @return array{id: string, url: string}
     */
    public function createInvoice(Order $order, string $ipnUrl, string $successUrl, string $cancelUrl): array
    {
        $amount = intdiv($order->amount_kopecks, 100)
            + (($order->amount_kopecks % 100) / 100);

        $response = $this->request()->post(self::API_URL.'/invoice', [
            'price_amount' => $amount,
            'price_currency' => 'rub',
            'order_id' => $order->reference,
            'order_description' => 'VPN subscription: '.mb_substr((string) $order->tariff?->name, 0, 150),
            'ipn_callback_url' => $ipnUrl,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ]);

        $data = $response->json();
        $invoiceId = is_array($data) ? (string) ($data['id'] ?? '') : '';
        $invoiceUrl = is_array($data) ? (string) ($data['invoice_url'] ?? '') : '';

        if (! $response->successful()
            || $invoiceId === ''
            || strlen($invoiceId) > 255
            || ! $this->isTrustedInvoiceUrl($invoiceUrl)) {
            throw new PaymentGatewayException('NOWPayments could not create a valid invoice.');
        }

        return ['id' => $invoiceId, 'url' => $invoiceUrl];
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayment(string $paymentId): array
    {
        $response = $this->request()->get(self::API_URL.'/payment/'.rawurlencode($paymentId));
        $data = $response->json();

        if (! $response->successful() || ! is_array($data)) {
            throw new PaymentGatewayException('NOWPayments payment verification failed.');
        }

        return $data;
    }

    private function request(): PendingRequest
    {
        $apiKey = PaymentSettings::current()->nowpayments_api_key;

        if (! filled($apiKey)) {
            throw new PaymentGatewayException('NOWPayments is not configured.');
        }

        return Http::acceptJson()
            ->withHeaders(['x-api-key' => $apiKey])
            ->timeout(15);
    }

    private function isTrustedInvoiceUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        return ($parts['scheme'] ?? null) === 'https'
            && ($host === 'nowpayments.io' || str_ends_with($host, '.nowpayments.io'));
    }
}
