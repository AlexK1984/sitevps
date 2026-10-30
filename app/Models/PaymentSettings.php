<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'yookassa_enabled',
    'telegram_stars_enabled',
    'crypto_asset',
    'crypto_network',
    'crypto_confirmations',
    'crypto_payment_ttl_minutes',
    'crypto_admin_notes',
])]
class PaymentSettings extends Model
{
    protected function casts(): array
    {
        return [
            'yookassa_enabled' => 'boolean',
            'telegram_stars_enabled' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate(['id' => 1], [
            'yookassa_enabled' => true,
            'telegram_stars_enabled' => false,
        ]);
    }
}
