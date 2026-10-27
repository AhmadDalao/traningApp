<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', fn (Blueprint $table) => $table->json('training_goals')->nullable());
        Schema::table('schedule_entries', function (Blueprint $table) {
            $table->unsignedInteger('estimated_calories')->nullable();
            $table->string('intensity', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('schedule_entries', fn (Blueprint $table) => $table->dropColumn(['estimated_calories', 'intensity']));
        Schema::table('user_profiles', fn (Blueprint $table) => $table->dropColumn('training_goals'));
    }
};
