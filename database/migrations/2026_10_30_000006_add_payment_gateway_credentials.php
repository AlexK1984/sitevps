<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_settings', function (Blueprint $table) {
            $table->text('yookassa_shop_id')->nullable();
            $table->text('yookassa_secret_key')->nullable();
            $table->text('telegram_bot_token')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payment_settings', function (Blueprint $table) {
            $table->dropColumn([
                'yookassa_shop_id',
                'yookassa_secret_key',
                'telegram_bot_token',
            ]);
        });
    }
};
