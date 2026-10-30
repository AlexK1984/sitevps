<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('base_url');
            $table->string('subscription_url_prefix')->nullable();
            $table->string('username');
            $table->text('password');
            $table->unsignedBigInteger('inbound_id');
            $table->string('protocol', 24)->default('vless');
            $table->string('flow')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);
            $table->foreignId('assigned_panel_id')->nullable()->constrained('panels')->nullOnDelete();
            $table->string('telegram_user_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['assigned_panel_id']);
            $table->dropColumn(['is_admin', 'assigned_panel_id', 'telegram_user_id']);
        });

        Schema::dropIfExists('panels');
    }
};
