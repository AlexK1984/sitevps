<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
        });

        Schema::create('site_contents', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('site_name', 80);
            $table->string('meta_title', 70);
            $table->string('meta_description', 180);
            $table->string('canonical_url', 255)->nullable();
            $table->string('og_image_url', 500)->nullable();
            $table->string('hero_badge', 100);
            $table->string('hero_title', 120);
            $table->text('hero_body');
            $table->string('hero_cta_label', 60);
            $table->string('hero_cta_target', 16)->default('#plans');
            $table->string('hero_fact_one', 80);
            $table->string('hero_fact_two', 80);
            $table->string('plans_eyebrow', 80);
            $table->string('plans_title', 120);
            $table->text('plans_description');
            $table->string('steps_eyebrow', 80);
            $table->string('steps_title', 120);
            $table->string('step_one_title', 80);
            $table->text('step_one_body');
            $table->string('step_two_title', 80);
            $table->text('step_two_body');
            $table->string('step_three_title', 80);
            $table->text('step_three_body');
            $table->string('footer_text', 180);
            $table->timestamps();
        });

        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->string('summary', 500);
            $table->timestamps();

            $table->index(['subject_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');
        Schema::dropIfExists('site_contents');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
