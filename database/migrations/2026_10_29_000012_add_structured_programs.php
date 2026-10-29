<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_plans', function (Blueprint $table) {
            $table->string('structure_type', 16)->default('simple');
            $table->unsignedTinyInteger('duration_weeks')->nullable();
        });
        Schema::create('training_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_plan_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->unsignedTinyInteger('start_week');
            $table->unsignedTinyInteger('end_week');
            $table->unsignedTinyInteger('position');
            $table->string('type', 16)->default('normal');
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::table('training_days', fn (Blueprint $table) => $table->foreignId('training_block_id')->nullable()->constrained()->nullOnDelete());
        Schema::create('program_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('started_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('start_token', 64)->unique();
            $table->date('started_on');
            $table->string('status', 16)->default('active');
            $table->dateTime('completed_at')->nullable();
            $table->json('definition_snapshot');
            $table->timestamps();
        });
        Schema::table('schedule_entries', function (Blueprint $table) {
            $table->foreignId('program_run_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('program_week')->nullable();
            $table->json('program_context')->nullable();
            $table->json('program_prescription')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('schedule_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('program_run_id');
            $table->dropColumn(['program_week', 'program_context', 'program_prescription']);
        });
        Schema::dropIfExists('program_runs');
        Schema::table('training_days', fn (Blueprint $table) => $table->dropConstrainedForeignId('training_block_id'));
        Schema::dropIfExists('training_blocks');
        Schema::table('training_plans', fn (Blueprint $table) => $table->dropColumn(['structure_type', 'duration_weeks']));
    }
};
