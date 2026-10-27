<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['training_days', 'training_exercises', 'schedule_entries'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->string('icon', 30)->nullable());
        }
    }

    public function down(): void
    {
        foreach (['training_days', 'training_exercises', 'schedule_entries'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('icon'));
        }
    }
};
