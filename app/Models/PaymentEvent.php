<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'order_id',
    'actor_id',
    'provider',
    'event_type',
    'status_from',
    'status_to',
    'summary',
    'idempotency_key',
])]
class PaymentEvent extends Model
{
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function providerLabel(): string
    {
        return match ($this->provider) {
            'yookassa' => 'ЮKassa',
            'telegram_stars' => 'Telegram Stars',
            'internal_test' => 'Внутренний тест',
            default => 'Платёжная система',
        };
    }

    public function eventTypeLabel(): string
    {
        return match ($this->event_type) {
            'order_created' => 'Заказ создан',
            'payment_request_created' => 'Платёж создан у провайдера',
            'payment_request_failed' => 'Ошибка создания платежа',
            'webhook_verified' => 'Webhook проверен',
            'webhook_rejected' => 'Webhook отклонён проверкой',
            'payment_status_received' => 'Статус платежа подтверждён',
            'test_simulated' => 'Тестовая оплата смоделирована',
            'test_mode_enabled' => 'Тестовый режим включён',
            'test_mode_disabled' => 'Тестовый режим выключен',
            'provisioning_started' => 'Начата выдача доступа',
            'provisioning_queued' => 'Выдача доступа поставлена в очередь',
            'provisioning_failed' => 'Ошибка выдачи доступа',
            'provisioning_completed' => 'Доступ выдан',
            'provisioning_retry_requested' => 'Запрошен повтор выдачи',
            'provisioning_waiting_for_panel' => 'Ожидает назначения панели',
            default => 'Событие обработки платежа',
        };
    }
}
