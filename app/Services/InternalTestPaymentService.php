<?php

namespace App\Services;

use App\Exceptions\InternalTestPaymentException;
use App\Models\Order;
use App\Models\PaymentSettings;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InternalTestPaymentService
{
    public function __construct(private readonly PaymentEventLogger $events) {}

    public function simulateForCustomer(User $user, Tariff $tariff, string $idempotencyKey): array
    {
        return $this->simulate($user, $tariff, $user, $idempotencyKey, customerInitiated: true);
    }

    public function simulate(
        User $user,
        Tariff $tariff,
        User $actor,
        string $idempotencyKey,
        bool $customerInitiated = false,
    ): array {
        return DB::transaction(function () use ($user, $tariff, $actor, $idempotencyKey, $customerInitiated): array {
            $settings = PaymentSettings::query()->whereKey(1)->lockForUpdate()->first();

            if (! $settings?->internal_test_payments_enabled) {
                throw new InternalTestPaymentException('Внутренний тестовый режим выключен.');
            }

            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $tariff = Tariff::query()->lockForUpdate()->findOrFail($tariff->id);
            if ($customerInitiated && ! $user->is_active) {
                throw new InternalTestPaymentException('Учётная запись отключена.');
            }

            $subscription = $user->subscription()->lockForUpdate()->first();
            $isReplacement = ($subscription?->isActive() ?? false)
                && $subscription->tariff_id !== $tariff->id;

            $existingOrder = Order::query()
                ->where('test_idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existingOrder) {
                if ($existingOrder->user_id !== $user->id || $existingOrder->tariff_id !== $tariff->id) {
                    throw new InternalTestPaymentException('Ключ тестовой операции уже использован для другого заказа.');
                }

                return [$existingOrder, false];
            }

            if (! $tariff->is_active || ! $user->assigned_panel_id) {
                throw new InternalTestPaymentException('Для тестовой выдачи нужен активный тариф и назначенная панель.');
            }

            $order = Order::query()->create([
                'reference' => (string) Str::uuid(),
                'user_id' => $user->id,
                'tariff_id' => $tariff->id,
                'panel_id' => $user->assigned_panel_id,
                'amount_kopecks' => $tariff->price_kopecks,
                'status' => 'paid_unassigned',
                'paid_at' => now(),
                'subscription_action' => $isReplacement ? 'replacement' : 'renewal',
                'payment_method' => 'internal_test',
                'payment_currency' => 'TST',
                'payment_amount_units' => null,
                'is_test' => true,
                'test_idempotency_key' => $idempotencyKey,
            ]);

            $this->events->record(
                'internal_test',
                'test_simulated',
                $customerInitiated
                    ? 'Тестовая оплата создана пользователем; запускается обычная выдача на назначенной панели без обращения к платёжному провайдеру.'
                    : 'Тестовая оплата создана администратором; запускается обычная выдача на назначенной панели без обращения к платёжному провайдеру.',
                $order,
                $actor->id,
                'pending',
                'paid_unassigned',
                'test-simulation:'.$idempotencyKey,
            );

            return [$order, true];
        });
    }
}
