<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tariff_id')->constrained()->restrictOnDelete();
            $table->foreignId('panel_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('amount_kopecks');
            $table->string('status', 32)->default('pending');
            $table->string('payment_id')->nullable()->unique();
            $table->text('payment_url')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('tariff_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('panel_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('client_uuid')->unique();
            $table->string('sub_id', 32)->unique();
            $table->string('client_email')->unique();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('traffic_limit_bytes')->default(0);
            $table->unsignedSmallInteger('connection_limit')->default(1);
            $table->string('status', 32)->default('pending');
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('subscription_order_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_order_applications');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('orders');
    }
};
