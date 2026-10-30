<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tariffs', function (Blueprint $table) {
            $table->unsignedInteger('price_stars')->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_method', 32)->default('yookassa');
            $table->string('payment_currency', 3)->default('RUB');
            $table->unsignedBigInteger('payment_amount_units')->nullable();
            $table->string('telegram_pre_checkout_id')->nullable();
            $table->timestamp('telegram_pre_checkout_at')->nullable();
        });

        Schema::create('payment_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('yookassa_enabled')->default(true);
            $table->boolean('telegram_stars_enabled')->default(false);
            $table->string('crypto_asset', 20)->nullable();
            $table->string('crypto_network', 80)->nullable();
            $table->unsignedSmallInteger('crypto_confirmations')->nullable();
            $table->unsignedSmallInteger('crypto_payment_ttl_minutes')->nullable();
            $table->text('crypto_admin_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_settings');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'payment_method',
                'payment_currency',
                'payment_amount_units',
                'telegram_pre_checkout_id',
                'telegram_pre_checkout_at',
            ]);
        });

        Schema::table('tariffs', function (Blueprint $table) {
            $table->dropColumn('price_stars');
        });
    }
};
