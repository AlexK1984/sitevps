<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('panels', function (Blueprint $table) {
            $table->string('connection_status', 16)->nullable();
            $table->timestamp('connection_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('panels', function (Blueprint $table) {
            $table->dropColumn(['connection_status', 'connection_checked_at']);
        });
    }
};
