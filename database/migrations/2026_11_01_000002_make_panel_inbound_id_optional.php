<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('panels', function (Blueprint $table) {
            $table->unsignedBigInteger('inbound_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('panels')->whereNull('inbound_id')->exists()) {
            throw new RuntimeException('Cannot make panels.inbound_id required while panels without an inbound ID exist.');
        }

        Schema::table('panels', function (Blueprint $table) {
            $table->unsignedBigInteger('inbound_id')->nullable(false)->change();
        });
    }
};
