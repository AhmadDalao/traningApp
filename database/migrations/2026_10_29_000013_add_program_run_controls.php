<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('program_runs', function (Blueprint $table) {
            $table->date('paused_from')->nullable();
            $table->date('resume_on')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->json('ended_position')->nullable();
            // Date offsets from original occurrence dates, used only by explicit definition reconciliation.
            $table->json('schedule_shifts')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('program_runs', fn (Blueprint $table) => $table->dropColumn(['paused_from', 'resume_on', 'ended_at', 'ended_position', 'schedule_shifts']));
    }
};
