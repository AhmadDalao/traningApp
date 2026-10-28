<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_entries', function (Blueprint $table) {
            // Existing occurrence keys prove origin, but not whether the time was edited.
            // Older sessions stay independent; only newly generated, untouched sessions opt in.
            $table->boolean('schedule_managed')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('schedule_entries', fn (Blueprint $table) => $table->dropColumn('schedule_managed'));
    }
};
