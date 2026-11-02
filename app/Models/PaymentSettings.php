<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'yookassa_enabled',
    'telegram_stars_enabled',
    'internal_test_payments_enabled',
    'yookassa_shop_id',
    'yookassa_secret_key',
    'telegram_bot_token',
    'smtp_enabled',
    'smtp_host',
    'smtp_port',
    'smtp_encryption',
    'smtp_username',
    'smtp_password',
    'smtp_from_address',
    'smtp_from_name',
    'nowpayments_api_key',
    'nowpayments_ipn_secret',
    'nowpayments_enabled',
    'nowpayments_rub_verified',
    'default_panel_id',
])]
#[Hidden([
    'yookassa_shop_id',
    'yookassa_secret_key',
    'telegram_bot_token',
    'smtp_username',
    'smtp_password',
    'nowpayments_api_key',
    'nowpayments_ipn_secret',
])]
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
            'smtp_enabled' => 'boolean',
            'smtp_port' => 'integer',
            'smtp_username' => 'encrypted',
            'smtp_password' => 'encrypted',
            'nowpayments_api_key' => 'encrypted',
            'nowpayments_ipn_secret' => 'encrypted',
            'nowpayments_enabled' => 'boolean',
            'nowpayments_rub_verified' => 'boolean',
            'default_panel_id' => 'integer',
        ];
    }

    public function defaultPanel(): BelongsTo
    {
        return $this->belongsTo(Panel::class, 'default_panel_id');
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

    public function smtpConfiguredInAdmin(): bool
    {
        return $this->smtp_enabled
            && filled($this->smtp_host)
            && filled($this->smtp_port)
            && filled($this->smtp_from_address);
    }

    public function smtpPasswordConfiguredInAdmin(): bool
    {
        return filled($this->smtp_password);
    }

    public function smtpUsernameConfiguredInAdmin(): bool
    {
        return filled($this->smtp_username);
    }

    public function nowpaymentsConfiguredInAdmin(): bool
    {
        return filled($this->nowpayments_api_key) && filled($this->nowpayments_ipn_secret);
    }
}
