<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'yookassa_enabled',
    'telegram_stars_enabled',
    'internal_test_payments_enabled',
    'crypto_asset',
    'crypto_network',
    'crypto_confirmations',
    'crypto_payment_ttl_minutes',
    'crypto_admin_notes',
    'yookassa_shop_id',
    'yookassa_secret_key',
    'telegram_bot_token',
])]
#[Hidden(['yookassa_shop_id', 'yookassa_secret_key', 'telegram_bot_token'])]
class PaymentSettings extends Model
{
    protected function casts(): array
    {
        return [
            'yookassa_enabled' => 'boolean',
            'telegram_stars_enabled' => 'boolean',
            'internal_test_payments_enabled' => 'boolean',
            'yookassa_shop_id' => 'encrypted',
            'yookassa_secret_key' => 'encrypted',
            'telegram_bot_token' => 'encrypted',
        ];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate(['id' => 1], [
            'yookassa_enabled' => true,
            'telegram_stars_enabled' => false,
            'internal_test_payments_enabled' => false,
        ]);
    }

    public function yookassaCredentials(): array
    {
        $shopId = $this->yookassa_shop_id;
        $secretKey = $this->yookassa_secret_key;

        if (filled($shopId) || filled($secretKey)) {
            return ['shop_id' => $shopId, 'secret_key' => $secretKey];
        }

        return [
            'shop_id' => config('services.yookassa.shop_id'),
            'secret_key' => config('services.yookassa.secret_key'),
        ];
    }

    public function telegramBotToken(): ?string
    {
        return $this->telegram_bot_token ?: config('services.telegram.bot_token');
    }

    public function yookassaConfiguredInAdmin(): bool
    {
        return filled($this->yookassa_shop_id) && filled($this->yookassa_secret_key);
    }

    public function telegramConfiguredInAdmin(): bool
    {
        return filled($this->telegram_bot_token);
    }
}
