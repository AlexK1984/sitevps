<?php

namespace App\Services;

use App\Exceptions\PaymentGatewayException;
use App\Models\Order;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Support\Str;

class NowPaymentsCheckoutService
{
    public function __construct(
        private readonly NowPaymentsClient $nowPayments,
        private readonly PaymentEventLogger $events,
    ) {}

    public function create(User $user, Tariff $tariff): Order
    {
        $order = Order::query()->create([
            'reference' => (string) Str::uuid(),
            'user_id' => $user->id,
            'tariff_id' => $tariff->id,
            'panel_id' => $user->assigned_panel_id,
            'amount_kopecks' => $tariff->price_kopecks,
            'payment_method' => 'nowpayments',
            'payment_currency' => 'RUB',
            'payment_amount_units' => $tariff->price_kopecks,
            'status' => 'pending',
        ]);

        $this->events->record(
            'nowpayments',
            'order_created',
            'Создан заказ для оплаты через NOWPayments.',
            $order,
            statusTo: 'pending',
        );

        try {
            $invoice = $this->nowPayments->createInvoice(
                $order->load('tariff'),
                route('webhooks.nowpayments', absolute: true),
                route('dashboard', absolute: true),
                route('dashboard', absolute: true),
            );
        } catch (PaymentGatewayException $exception) {
            $order->update([
                'status' => 'payment_failed',
                'error_message' => $exception->getMessage(),
            ]);
            $this->events->record(
                'nowpayments',
                'invoice_request_failed',
                'NOWPayments не смог создать счёт.',
                $order,
                statusFrom: 'pending',
                statusTo: 'payment_failed',
            );

            throw $exception;
        }

        $order->update([
            'nowpayments_invoice_id' => $invoice['id'],
            'payment_url' => $invoice['url'],
        ]);
        $this->events->record(
            'nowpayments',
            'invoice_created',
            'NOWPayments создал hosted invoice в RUB.',
            $order,
            statusFrom: 'pending',
            statusTo: 'pending',
        );

        return $order->fresh();
    }
}
