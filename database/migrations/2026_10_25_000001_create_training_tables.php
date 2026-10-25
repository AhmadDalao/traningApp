<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('training_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_plan_id')->constrained()->cascadeOnDelete();
            $table->string('title', 150);
            $table->string('type', 20);
            $table->string('focus_area', 50)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
        Schema::create('training_exercises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_day_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->unsignedTinyInteger('sets');
            $table->string('reps', 100);
            $table->string('superset_group', 10)->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
        Schema::create('schedule_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_day_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 150);
            $table->string('entry_type', 20);
            $table->string('focus_area', 50)->nullable();
            $table->date('scheduled_date');
            $table->string('status', 20)->default('planned');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'scheduled_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_entries');
        Schema::dropIfExists('training_exercises');
        Schema::dropIfExists('training_days');
        Schema::dropIfExists('training_plans');
    }
};
