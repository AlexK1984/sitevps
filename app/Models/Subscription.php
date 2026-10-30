<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'tariff_id', 'panel_id', 'client_uuid', 'sub_id', 'client_email', 'expires_at', 'traffic_limit_bytes', 'connection_limit', 'status', 'last_error'])]
class Subscription extends Model
{
    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
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
        if ($this->status === 'active' && ! $this->expires_at?->isFuture()) {
            return 'Истекла';
        }

        return [
            'pending' => 'Ожидает оплаты',
            'provisioning' => 'Выдаём доступ',
            'provisioning_failed' => 'Нужна проверка',
            'active' => 'Активна',
        ][$this->status] ?? $this->status;
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->expires_at?->isFuture();
    }
}
