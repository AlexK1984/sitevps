<?php

namespace App\Services;

use App\Exceptions\PaymentGatewayException;
use App\Models\Order;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Support\Str;

class CheckoutService
{
    public function __construct(private readonly YooKassaClient $yooKassa) {}

    public function create(User $user, Tariff $tariff, string $returnUrl): Order
    {
        $order = Order::create([
            'reference' => (string) Str::uuid(),
            'user_id' => $user->id,
            'tariff_id' => $tariff->id,
            'panel_id' => $user->assigned_panel_id,
            'amount_kopecks' => $tariff->price_kopecks,
            'payment_method' => 'yookassa',
            'payment_currency' => 'RUB',
            'payment_amount_units' => $tariff->price_kopecks,
            'status' => 'pending',
        ]);

        try {
            $payment = $this->yooKassa->createPayment($order->load('tariff'), $returnUrl);
        } catch (PaymentGatewayException $exception) {
            $order->update([
                'status' => 'payment_failed',
                'error_message' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $order->update([
            'payment_id' => $payment['id'],
            'payment_url' => $payment['url'],
        ]);

        return $order->fresh();
    }

    public function createTelegramStars(User $user, Tariff $tariff): Order
    {
        if (! $tariff->price_stars || $tariff->price_stars < 1) {
            throw new PaymentGatewayException('Для тарифа не задана цена в Telegram Stars.');
        }

        return Order::create([
            'reference' => (string) Str::uuid(),
            'user_id' => $user->id,
            'tariff_id' => $tariff->id,
            'panel_id' => $user->assigned_panel_id,
            'amount_kopecks' => $tariff->price_kopecks,
            'payment_method' => 'telegram_stars',
            'payment_currency' => 'XTR',
            'payment_amount_units' => $tariff->price_stars,
            'status' => 'pending',
        ]);
    }
}
