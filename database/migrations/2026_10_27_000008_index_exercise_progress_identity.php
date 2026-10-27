<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (Schema::getIndexes('session_exercises') as $index) {
            if (($index['columns'][0] ?? null) === 'source_exercise_key') {
                return;
            }
        }
        Schema::table('session_exercises', fn (Blueprint $table) => $table->index('source_exercise_key'));
    }

    public function down(): void
    {
        if (Schema::hasIndex('session_exercises', 'session_exercises_source_exercise_key_index')) {
            Schema::table('session_exercises', fn (Blueprint $table) => $table->dropIndex('session_exercises_source_exercise_key_index'));
        }
    }
};
