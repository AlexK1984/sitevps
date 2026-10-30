<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'reference',
    'user_id',
    'tariff_id',
    'panel_id',
    'amount_kopecks',
    'status',
    'payment_id',
    'payment_url',
    'payment_method',
    'payment_currency',
    'payment_amount_units',
    'telegram_pre_checkout_id',
    'telegram_pre_checkout_at',
    'paid_at',
    'is_test',
    'test_idempotency_key',
    'error_message',
])]
class Order extends Model
{
    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'telegram_pre_checkout_at' => 'datetime',
            'is_test' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class);
    }

    public function panel(): BelongsTo
    {
        return $this->belongsTo(Panel::class);
    }

    public function statusLabel(): string
    {
        return [
            'pending' => 'Ожидает оплаты',
            'payment_failed' => 'Ошибка оплаты',
            'paid_unassigned' => 'Оплачено, ожидает назначения панели',
            'provisioning' => 'Выдаём доступ',
            'provisioning_failed' => 'Ошибка выдачи доступа',
            'active' => 'Доступ активирован',
        ][$this->status] ?? $this->status;
    }

    public function paymentMethodLabel(): string
    {
        if ($this->is_test) {
            return 'TEST · внутренняя симуляция';
        }

        return match ($this->payment_method) {
            'telegram_stars' => 'Telegram Stars',
            default => 'ЮKassa',
        };
    }

    public function paidAmountLabel(): string
    {
        if ($this->is_test) {
            return 'TEST · без списания';
        }

        if ($this->payment_currency === 'XTR') {
            return number_format($this->payment_amount_units ?? 0).' ⭐';
        }

        return number_format($this->amount_kopecks / 100, 2, ',', ' ').' ₽';
    }
}
