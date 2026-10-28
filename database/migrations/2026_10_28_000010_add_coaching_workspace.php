<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('account_type', 20)->default('trainee');
            $table->string('training_code', 11)->nullable()->unique();
        });
        DB::table('users')->orderBy('id')->chunkById(200, function ($users) {
            foreach ($users as $user) {
                do {
                    $code = 'TP-';
                    for ($i = 0; $i < 8; $i++) {
                        $code .= '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'[random_int(0, 31)];
                    }
                } while (DB::table('users')->where('training_code', $code)->exists());
                DB::table('users')->where('id', $user->id)->update(['training_code' => $code]);
            }
        });
        Schema::table('users', fn (Blueprint $table) => $table->string('training_code', 11)->nullable(false)->change());
        Schema::table('training_plans', fn (Blueprint $table) => $table->string('workspace_type', 20)->default('personal'));
        Schema::table('schedule_entries', fn (Blueprint $table) => $table->foreignId('scheduled_by_user_id')->nullable()->constrained('users')->nullOnDelete());
        Schema::create('coach_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('display_name', 100);
            $table->string('specialization', 150)->nullable();
            $table->text('bio')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('coach_trainee_relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coach_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('trainee_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_reason', 30)->nullable();
            $table->timestamps();
            $table->index(['coach_user_id', 'status'], 'coach_relationship_status');
            $table->index(['trainee_user_id', 'status'], 'trainee_relationship_status');
        });
        Schema::create('coach_pairing_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trainee_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
        Schema::create('coach_subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coach_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->unsignedInteger('duration_days')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('coach_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coach_trainee_relationship_id')->constrained()->cascadeOnDelete();
            $table->foreignId('coach_subscription_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('package_name', 100);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['coach_trainee_relationship_id', 'status'], 'relationship_subscription_status');
        });
        Schema::create('coach_plan_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coach_trainee_relationship_id')->constrained()->cascadeOnDelete();
            $table->foreignId('coach_subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_training_plan_id')->nullable()->constrained('training_plans')->nullOnDelete();
            $table->foreignId('assigned_training_plan_id')->nullable()->unique()->constrained('training_plans')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->string('status', 20)->default('active');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['coach_trainee_relationship_id', 'status'], 'relationship_assignment_status');
        });
    }

    public function down(): void
    {
        foreach (['coach_plan_assignments', 'coach_subscriptions', 'coach_subscription_plans', 'coach_pairing_tokens', 'coach_trainee_relationships', 'coach_profiles'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('schedule_entries', fn (Blueprint $table) => $table->dropConstrainedForeignId('scheduled_by_user_id'));
        Schema::table('training_plans', fn (Blueprint $table) => $table->dropColumn('workspace_type'));
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['training_code']);
            $table->dropColumn(['account_type', 'training_code']);
        });
    }
};
