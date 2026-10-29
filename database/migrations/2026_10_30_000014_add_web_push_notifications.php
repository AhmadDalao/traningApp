<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('workout_reminders')->default(false);
            $table->unsignedSmallInteger('reminder_minutes')->default(60);
            $table->boolean('coach_updates')->default(false);
            $table->timestamps();
        });
        Schema::create('web_push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('endpoint_hash', 64)->unique();
            $table->char('device_hash', 64)->unique();
            $table->text('endpoint');
            $table->text('public_key');
            $table->text('auth_token');
            $table->timestamps();
        });
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('web_push_subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_entry_id')->nullable()->constrained()->cascadeOnDelete();
            $table->char('dedupe_key', 64)->unique();
            $table->string('kind', 32);
            $table->json('context');
            $table->json('payload');
            $table->string('status', 16)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('expires_at');
            $table->uuid('attempt_token')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('last_result', 24)->nullable();
            $table->timestamps();
            $table->index(['status', 'available_at']);
        });
        // Existing user/date index cannot serve the global, date-bounded due scan.
        Schema::table('schedule_entries', fn (Blueprint $table) => $table->index(['scheduled_date', 'scheduled_time'], 'schedule_reminder_due'));
    }

    public function down(): void
    {
        Schema::table('schedule_entries', fn (Blueprint $table) => $table->dropIndex('schedule_reminder_due'));
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('web_push_subscriptions');
        Schema::dropIfExists('notification_preferences');
    }
};
