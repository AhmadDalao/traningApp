<?php

namespace Tests\Feature;

use App\Models\CoachPlanAssignment;
use App\Models\CoachTraineeRelationship;
use App\Models\TrainingPlan;
use App\Models\User;
use App\Services\TrainingPlanDuplicator;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PDOException;
use Tests\TestCase;

class CoachingTransactionTest extends TestCase
{
    use DatabaseMigrations;

    public function test_deadlock_after_media_copy_rolls_back_without_replaying_file_side_effects(): void
    {
        Storage::fake('public');
        $coach = User::factory()->create();
        $coach->forceFill(['account_type' => 'coach'])->save();
        $coach->coachProfile()->create(['display_name' => 'Coach']);
        $trainee = User::factory()->create();
        $relationship = CoachTraineeRelationship::create(['coach_user_id' => $coach->id, 'trainee_user_id' => $trainee->id, 'status' => 'active', 'requested_at' => now()]);
        $relationship->subscriptions()->create(['package_name' => 'QA', 'starts_on' => today(), 'status' => 'active']);
        $plan = $coach->trainingPlans()->create(['name' => 'Source']);
        $plan->forceFill(['workspace_type' => 'coach_template'])->save();
        $day = $plan->trainingDays()->create(['title' => 'Strength', 'type' => 'training']);
        $path = UploadedFile::fake()->image('source.jpg')->store('exercise-thumbnails', 'public');
        $day->exercises()->create(['name' => 'Squat', 'sets' => 1, 'reps' => '10', 'thumbnail_path' => $path, 'position' => 0]);
        $calls = 0;
        CoachPlanAssignment::creating(function () use (&$calls) {
            if (++$calls === 1) {
                throw new PDOException('Deadlock found when trying to get lock');
            }
        });
        $failed = false;
        try {
            app(TrainingPlanDuplicator::class)->assign($coach, $trainee, $plan, []);
        } catch (PDOException $error) {
            $failed = true;
        }
        $this->assertTrue($failed, 'A filesystem-producing transaction must not replay silently after a deadlock.');
        $this->assertSame(1, $calls);
        $this->assertSame(1, TrainingPlan::count());
        $this->assertDatabaseCount('coach_plan_assignments', 0);
        $this->assertSame([$path], Storage::disk('public')->allFiles());
    }
}
