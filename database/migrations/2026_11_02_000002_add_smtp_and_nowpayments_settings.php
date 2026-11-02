<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_settings', function (Blueprint $table) {
            $table->boolean('nowpayments_enabled')->default(false);
            $table->boolean('nowpayments_rub_verified')->default(false);
            $table->boolean('smtp_enabled')->default(false);
            $table->string('smtp_host', 255)->nullable();
            $table->unsignedSmallInteger('smtp_port')->nullable();
            $table->string('smtp_encryption', 10)->nullable();
            $table->text('smtp_username')->nullable();
            $table->text('smtp_password')->nullable();
            $table->string('smtp_from_address')->nullable();
            $table->string('smtp_from_name')->nullable();
            $table->text('nowpayments_api_key')->nullable();
            $table->text('nowpayments_ipn_secret')->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('nowpayments_invoice_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['nowpayments_invoice_id']);
            $table->dropColumn('nowpayments_invoice_id');
        });

        Schema::table('payment_settings', function (Blueprint $table) {
            $table->dropColumn([
                'nowpayments_enabled',
                'nowpayments_rub_verified',
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
            ]);
        });
    }
};
