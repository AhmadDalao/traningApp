<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_plans', function (Blueprint $table) {
            $table->string('schedule_mode', 16)->default('fixed');
            $table->boolean('schedule_enabled')->default(false);
            $table->foreignId('schedule_actor_id')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::table('training_days', fn (Blueprint $table) => $table->json('schedule_rules')->nullable());
        // Moved occurrences keep their existing key. Deleted occurrences need a tombstone.
        Schema::create('schedule_occurrence_cancellations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_plan_id')->constrained()->cascadeOnDelete();
            $table->string('occurrence_key')->unique();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_occurrence_cancellations');
        Schema::table('training_days', fn (Blueprint $table) => $table->dropColumn('schedule_rules'));
        Schema::table('training_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('schedule_actor_id');
            $table->dropColumn(['schedule_mode', 'schedule_enabled']);
        });
    }
};
