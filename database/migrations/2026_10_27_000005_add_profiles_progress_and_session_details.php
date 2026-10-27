<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->date('date_of_birth')->nullable();
            $table->decimal('height_cm', 5, 2)->nullable();
            $table->decimal('current_weight_kg', 6, 2)->nullable();
            $table->string('training_goal', 40)->nullable();
            $table->string('body_weight_goal', 20)->nullable();
            $table->decimal('target_body_weight_kg', 6, 2)->nullable();
            $table->string('experience_level', 20)->nullable();
            $table->unsignedTinyInteger('training_days_per_week')->nullable();
            $table->unsignedSmallInteger('session_duration_minutes')->nullable();
            $table->json('equipment_access')->nullable();
            $table->timestamp('onboarding_completed_at')->nullable();
            $table->timestamp('onboarding_skipped_at')->nullable();
            $table->date('weight_check_in_dismissed_until')->nullable();
            $table->timestamps();
        });
        Schema::create('body_weight_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('weight_kg', 6, 2);
            $table->date('recorded_date');
            $table->string('note', 1000)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'recorded_date']);
        });
        Schema::table('schedule_entries', function (Blueprint $table) {
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->unsignedInteger('calories_burned')->nullable();
            $table->string('calories_source', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('schedule_entries', fn (Blueprint $table) => $table->dropColumn(['duration_minutes', 'calories_burned', 'calories_source']));
        Schema::dropIfExists('body_weight_entries');
        Schema::dropIfExists('user_profiles');
    }
};
