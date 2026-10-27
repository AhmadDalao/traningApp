<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_entries', function (Blueprint $table) {
            $table->dateTime('started_at')->nullable();
        });
        Schema::table('training_exercises', function (Blueprint $table) {
            $table->string('tracking_mode', 20)->nullable();
        });
        Schema::create('session_exercises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_training_exercise_id')->nullable()->constrained('training_exercises')->nullOnDelete();
            $table->string('source_exercise_key')->nullable();
            $table->string('name', 150);
            $table->string('tracking_mode', 20)->default('reps');
            $table->unsignedTinyInteger('planned_sets');
            $table->string('planned_reps_text', 100)->nullable();
            $table->decimal('planned_weight_kg', 6, 2)->nullable();
            $table->string('superset_group', 10)->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->string('custom_icon_path')->nullable();
            $table->string('icon', 30)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('position');
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['schedule_entry_id', 'position']);
        });
        Schema::create('session_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_exercise_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('set_number');
            $table->string('planned_reps_text', 100)->nullable();
            $table->decimal('planned_weight_kg', 6, 2)->nullable();
            $table->unsignedInteger('actual_reps')->nullable();
            $table->unsignedInteger('actual_duration_seconds')->nullable();
            $table->decimal('actual_weight_kg', 6, 2)->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['session_exercise_id', 'set_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_sets');
        Schema::dropIfExists('session_exercises');
        Schema::table('training_exercises', fn (Blueprint $table) => $table->dropColumn('tracking_mode'));
        Schema::table('schedule_entries', fn (Blueprint $table) => $table->dropColumn('started_at'));
    }
};
