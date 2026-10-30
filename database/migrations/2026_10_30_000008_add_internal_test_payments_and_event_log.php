<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_settings', function (Blueprint $table) {
            $table->boolean('internal_test_payments_enabled')->default(false);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('is_test')->default(false);
            $table->string('test_idempotency_key', 36)->nullable()->unique();
        });

        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider', 32);
            $table->string('event_type', 64);
            $table->string('status_from', 32)->nullable();
            $table->string('status_to', 32)->nullable();
            $table->string('summary', 255);
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->timestamps();

            $table->index(['order_id', 'created_at']);
            $table->index(['provider', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['test_idempotency_key']);
            $table->dropColumn(['is_test', 'test_idempotency_key']);
        });

        Schema::table('payment_settings', function (Blueprint $table) {
            $table->dropColumn('internal_test_payments_enabled');
        });
    }
};
