<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_exercises', function (Blueprint $table) {
            $table->decimal('target_weight', 8, 2)->nullable();
        });
        Schema::table('schedule_entries', function (Blueprint $table) {
            $table->time('scheduled_time')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->json('workout_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('schedule_entries', fn (Blueprint $table) => $table->dropColumn(['scheduled_time', 'completed_at', 'workout_snapshot']));
        Schema::table('training_exercises', fn (Blueprint $table) => $table->dropColumn('target_weight'));
    }
};
