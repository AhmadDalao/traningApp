<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_plans', function (Blueprint $table) {
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
        });
        Schema::table('training_days', function (Blueprint $table) {
            $table->json('weekdays')->nullable();
            $table->time('default_time')->nullable();
        });
        Schema::table('training_exercises', function (Blueprint $table) {
            $table->string('library_key')->nullable();
            $table->string('custom_icon_path')->nullable();
        });
        Schema::create('exercise_library_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('focus_area', 50)->nullable();
            $table->string('icon', 40)->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->string('custom_icon_path')->nullable();
            $table->timestamps();
        });
        Schema::table('schedule_entries', function (Blueprint $table) {
            $table->string('plan_occurrence_key')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('schedule_entries', fn (Blueprint $table) => $table->dropColumn('plan_occurrence_key'));
        Schema::dropIfExists('exercise_library_items');
        Schema::table('training_exercises', fn (Blueprint $table) => $table->dropColumn(['library_key', 'custom_icon_path']));
        Schema::table('training_days', fn (Blueprint $table) => $table->dropColumn(['weekdays', 'default_time']));
        Schema::table('training_plans', fn (Blueprint $table) => $table->dropColumn(['start_date', 'end_date']));
    }
};
