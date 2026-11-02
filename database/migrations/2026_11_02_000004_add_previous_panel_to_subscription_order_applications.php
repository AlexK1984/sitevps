<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_order_applications', function (Blueprint $table) {
            $table->foreignId('previous_panel_id')->nullable()->after('subscription_id')->constrained('panels')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscription_order_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('previous_panel_id');
        });
    }
};
