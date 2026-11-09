<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coach_subscriptions', function (Blueprint $table) {
            $table->foreignId('coach_plan_assignment_id')->nullable()->constrained('coach_plan_assignments')->nullOnDelete();
        });
        $this->separateCoverage();
    }

    public function separateCoverage(): void
    {
        // Preserve every granted period. Split shared coverage without changing any
        // program, workout, date, status or package definition.
        DB::transaction(function () {
            foreach (DB::table('coach_subscriptions')->orderBy('id')->get() as $subscription) {
                $assignments = DB::table('coach_plan_assignments')->where('coach_subscription_id', $subscription->id)->orderBy('id')->get();
                foreach ($assignments as $index => $assignment) {
                    if ($index === 0) {
                        DB::table('coach_subscriptions')->where('id', $subscription->id)->update(['coach_plan_assignment_id' => $assignment->id]);
                    } else {
                        $copy = (array) $subscription;
                        unset($copy['id']);
                        $copy['coach_plan_assignment_id'] = $assignment->id;
                        $id = DB::table('coach_subscriptions')->insertGetId($copy);
                        DB::table('coach_plan_assignments')->where('id', $assignment->id)->update(['coach_subscription_id' => $id]);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        // Never merge or delete service periods on rollback.
        Schema::table('coach_subscriptions', fn (Blueprint $table) => $table->dropConstrainedForeignId('coach_plan_assignment_id'));
    }
};
