<?php

namespace Tests\Feature;

use App\Models\CoachTraineeRelationship;
use App\Models\ExerciseLibraryItem;
use App\Models\TrainingPlan;
use App\Models\User;
use App\Services\ExerciseLibrary;
use App\Services\TrainingPlanDuplicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CoachingPlansTest extends TestCase
{
    use RefreshDatabase;

    private User $coach;

    private User $trainee;

    private CoachTraineeRelationship $relationship;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->coach = User::factory()->create();
        $this->coach->forceFill(['account_type' => 'coach'])->save();
        $this->coach->coachProfile()->create(['display_name' => 'Coach']);
        $this->trainee = User::factory()->create();
        $this->relationship = CoachTraineeRelationship::create(['coach_user_id' => $this->coach->id, 'trainee_user_id' => $this->trainee->id, 'status' => 'active', 'requested_by' => $this->coach->id, 'requested_at' => now(), 'approved_at' => now()]);
        $this->relationship->subscriptions()->create(['package_name' => 'Coaching', 'starts_on' => today(), 'ends_on' => today()->addDays(60), 'status' => 'active']);
        $this->base = '/coach/trainees/'.$this->trainee->id;
    }

    private function template(): TrainingPlan
    {
        $plan = $this->coach->trainingPlans()->create(['name' => 'Template', 'description' => 'Reusable']);
        $plan->forceFill(['workspace_type' => 'coach_template'])->save();
        $day = $plan->trainingDays()->create(['title' => 'Upper body', 'type' => 'training', 'weekdays' => [0, 2, 4], 'default_time' => '18:00', 'position' => 0]);
        $day->exercises()->create(['name' => 'Bench', 'sets' => 3, 'reps' => '8-12', 'target_weight' => 40, 'tracking_mode' => 'reps', 'library_key' => 'builtin:Barbell_Bench_Press_-_Medium_Grip', 'superset_group' => 'A', 'position' => 0]);
        $plan->trainingDays()->create(['title' => 'Rest', 'type' => 'rest', 'weekdays' => [5, 6], 'position' => 1]);

        return $plan;
    }

    private function assign(TrainingPlan $template): TrainingPlan
    {
        $this->actingAs($this->coach)->post($this->base.'/assign', ['training_plan_id' => $template->id, 'start_date' => today()->toDateString(), 'end_date' => today()->addDays(30)->toDateString(), 'confirmed' => true])->assertRedirect()->assertSessionHasNoErrors();

        return $this->trainee->trainingPlans()->latest('id')->firstOrFail();
    }

    public function test_coach_templates_are_separate_from_personal_workspaces_and_schedule_pickers(): void
    {
        $template = $this->template();
        $personal = $this->coach->trainingPlans()->create(['name' => 'My own training']);
        $this->actingAs($this->coach)->get('/coach/training-plans')->assertInertia(fn (Assert $p) => $p->has('plans.data', 1)->where('plans.data.0.id', $template->id));
        $this->get('/training-plans')->assertInertia(fn (Assert $p) => $p->has('plans.data', 1)->where('plans.data.0.id', $personal->id));
        $this->get('/training-plans/'.$template->id)->assertNotFound();
        $this->get('/coach/training-plans/'.$personal->id)->assertNotFound();
        $this->get('/calendar')->assertInertia(fn (Assert $p) => $p->has('plans', 1)->where('plans.0.id', $personal->id));
        $this->post('/schedule-entries', ['entry_type' => 'training', 'training_day_id' => $template->trainingDays->first()->id, 'scheduled_date' => today()->addDay()->toDateString()])->assertSessionHasErrors('training_day_id');
        $this->post('/coach/training-plans/'.$template->id.'/duplicate', ['confirmed' => true])->assertRedirect();
        $this->assertSame('coach_template', $this->coach->trainingPlans()->latest('id')->first()->workspace_type);
    }

    public function test_assignment_creates_an_independent_trainee_owned_copy_without_workouts(): void
    {
        $template = $this->template();
        $copy = $this->assign($template);
        $this->assertSame($this->trainee->id, $copy->user_id);
        $this->assertSame('coach_assigned', $copy->workspace_type);
        $this->assertCount(2, $copy->trainingDays);
        $a = $template->trainingDays->first()->exercises->first();
        $b = $copy->trainingDays->first()->exercises->first();
        $this->assertNotSame($a->id, $b->id);
        $this->assertEquals($a->only('sets', 'reps', 'target_weight', 'tracking_mode', 'superset_group', 'library_key'), $b->only('sets', 'reps', 'target_weight', 'tracking_mode', 'superset_group', 'library_key'));
        $this->assertDatabaseCount('schedule_entries', 0);
        $this->assertDatabaseCount('session_sets', 0);
        $template->update(['name' => 'Changed']);
        $a->update(['target_weight' => 90]);
        $this->assertSame('Template', $copy->fresh()->name);
        $this->assertEquals(40, $b->fresh()->target_weight);
        $this->assertSame($this->relationship->id, $copy->assignment->coach_trainee_relationship_id);
    }

    public function test_custom_exercise_media_is_independent_and_inherited_key_remains_editable(): void
    {
        $template = $this->template();
        $source = $template->trainingDays->first()->exercises->first();
        $path = UploadedFile::fake()->image('exercise.jpg')->store('exercise-thumbnails', 'public');
        $source->update(['thumbnail_path' => $path]);
        $paths = [];
        $library = app(ExerciseLibrary::class)->saveCustom($source, $this->coach->id, 'Chest', $paths);
        $source->update(['library_key' => 'custom:'.$library->id]);
        $copy = $this->assign($template);
        $day = $copy->trainingDays->first();
        $exercise = $day->exercises->first();
        $this->assertNotSame($path, $exercise->thumbnail_path);
        Storage::disk('public')->assertExists($exercise->thumbnail_path);
        $library->delete();
        $payload = ['title' => 'Updated', 'type' => 'training', 'exercises' => [...[], $exercise->only('id', 'name', 'sets', 'reps', 'target_weight', 'tracking_mode', 'library_key')]];
        $this->actingAs($this->trainee)->put('/training-days/'.$day->id, $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($source->library_key, $exercise->fresh()->library_key);
        $this->actingAs($this->coach)->put($this->base.'/training-days/'.$day->id, $payload)->assertRedirect()->assertSessionHasNoErrors();
        $foreign = ExerciseLibraryItem::create(['user_id' => User::factory()->create()->id, 'name' => 'Private', 'icon' => 'target']);
        $payload['exercises'][0]['library_key'] = 'custom:'.$foreign->id;
        $this->put($this->base.'/training-days/'.$day->id, $payload)->assertSessionHasErrors('exercises.0.library_key');
    }

    public function test_coach_cannot_modify_personal_other_coach_or_other_trainee_plans(): void
    {
        $copy = $this->assign($this->template());
        $personal = $this->trainee->trainingPlans()->create(['name' => 'Personal']);
        $other = User::factory()->create()->trainingPlans()->create(['name' => 'Other']);
        $this->put($this->base.'/training-plans/'.$copy->id, ['name' => 'Allowed'])->assertRedirect();
        foreach ([$personal, $other] as $plan) {
            $this->get($this->base.'/training-plans/'.$plan->id)->assertForbidden();
            $this->put($this->base.'/training-plans/'.$plan->id, ['name' => 'Forbidden'])->assertForbidden();
        }
        $this->put('/training-plans/'.$copy->id, ['name' => 'Cannot use owner endpoint'])->assertForbidden();
        $this->delete($this->base.'/training-plans/'.$copy->id)->assertForbidden();
        $this->post($this->base.'/training-plans/'.$copy->id.'/duplicate', ['confirmed' => true])->assertForbidden();
    }

    public function test_pausing_keeps_read_access_but_blocks_all_plan_mutations(): void
    {
        $copy = $this->assign($this->template());
        $day = $copy->trainingDays->first();
        $this->relationship->subscriptions()->update(['status' => 'paused']);
        $this->get($this->base.'/training-plans/'.$copy->id)->assertOk();
        $this->put($this->base.'/training-plans/'.$copy->id, ['name' => 'Forbidden'])->assertForbidden();
        $this->post($this->base.'/training-plans', ['name' => 'Forbidden'])->assertForbidden();
        $this->put($this->base.'/training-days/'.$day->id, ['title' => 'Forbidden', 'type' => 'rest'])->assertForbidden();
        $this->delete($this->base.'/training-days/'.$day->id)->assertForbidden();
        $this->post($this->base.'/training-days/'.$day->id.'/duplicate', ['confirmed' => true])->assertForbidden();
    }

    public function test_owner_can_duplicate_assigned_plan_and_coach_can_copy_days_only_between_own_assignments(): void
    {
        $copy = $this->assign($this->template());
        $second = $this->assign($this->template());
        $day = $copy->trainingDays->first();
        $this->post($this->base.'/training-days/'.$day->id.'/duplicate', ['confirmed' => true, 'training_plan_id' => $second->id])->assertRedirect();
        $this->assertCount(3, $second->fresh()->trainingDays);
        $personal = $this->trainee->trainingPlans()->create(['name' => 'Personal']);
        $this->post($this->base.'/training-days/'.$day->id.'/duplicate', ['confirmed' => true, 'training_plan_id' => $personal->id])->assertForbidden();
        $this->actingAs($this->trainee)->post('/training-plans/'.$copy->id.'/duplicate', ['confirmed' => true])->assertRedirect();
        $personalCopy = $this->trainee->trainingPlans()->latest('id')->first();
        $this->assertSame('personal', $personalCopy->workspace_type);
        $this->assertNull($personalCopy->assignment);
    }

    public function test_custom_plan_creation_and_end_assignment_preserve_owner_access(): void
    {
        $this->actingAs($this->coach)->post($this->base.'/training-plans', ['name' => 'Custom trainee plan'])->assertRedirect();
        $plan = $this->trainee->trainingPlans()->firstOrFail();
        $assignment = $plan->assignment;
        $this->assertSame('coach_assigned', $plan->workspace_type);
        $this->assertNull($assignment->source_training_plan_id);
        $this->post($this->base.'/assignments/'.$assignment->id.'/end', ['confirmed' => true])->assertRedirect();
        $this->get($this->base.'/training-plans/'.$plan->id)->assertOk();
        $this->put($this->base.'/training-plans/'.$plan->id, ['name' => 'Forbidden'])->assertForbidden();
        $this->actingAs($this->trainee)->put('/training-plans/'.$plan->id, ['name' => 'Mine'])->assertRedirect();
        $this->delete('/training-plans/'.$plan->id)->assertRedirect();
        $this->assertNull($assignment->fresh()->assigned_training_plan_id);
    }

    public function test_another_coach_assignment_and_a_new_consent_cycle_do_not_grant_old_plan_management(): void
    {
        $copy = $this->assign($this->template());
        $other = User::factory()->create();
        $other->forceFill(['account_type' => 'coach'])->save();
        $other->coachProfile()->create(['display_name' => 'Other coach']);
        $relation = CoachTraineeRelationship::create(['coach_user_id' => $other->id, 'trainee_user_id' => $this->trainee->id, 'status' => 'active', 'requested_at' => now(), 'approved_at' => now()]);
        $relation->subscriptions()->create(['package_name' => 'Other', 'starts_on' => today(), 'status' => 'active']);
        $this->actingAs($other)->get($this->base.'/training-plans/'.$copy->id)->assertNotFound();
        $this->put($this->base.'/training-plans/'.$copy->id, ['name' => 'Forbidden'])->assertNotFound();
        $this->actingAs($this->trainee)->post('/my-coaching/relationships/'.$this->relationship->id, ['action' => 'end', 'confirmed' => true])->assertRedirect();
        $new = CoachTraineeRelationship::create(['coach_user_id' => $this->coach->id, 'trainee_user_id' => $this->trainee->id, 'status' => 'active', 'requested_at' => now(), 'approved_at' => now()]);
        $new->subscriptions()->create(['package_name' => 'Fresh consent', 'starts_on' => today(), 'status' => 'active']);
        $this->actingAs($this->coach)->get($this->base.'/training-plans/'.$copy->id)->assertNotFound();
        $this->put($this->base.'/training-plans/'.$copy->id, ['name' => 'Forbidden'])->assertNotFound();
        $this->actingAs($this->trainee)->get('/training-plans/'.$copy->id)->assertOk();
        $this->assertSame('ended', $copy->assignment->fresh()->status);
    }

    public function test_source_deletion_and_failed_media_copy_preserve_independent_assignments(): void
    {
        $template = $this->template();
        $path = UploadedFile::fake()->image('source.jpg')->store('exercise-thumbnails', 'public');
        $template->trainingDays->first()->exercises->first()->update(['thumbnail_path' => $path]);
        $copy = $this->assign($template);
        $copiedPath = $copy->trainingDays->first()->exercises->first()->thumbnail_path;
        $this->delete('/coach/training-plans/'.$template->id)->assertRedirect();
        Storage::disk('public')->assertMissing($path);
        Storage::disk('public')->assertExists($copiedPath);
        $this->assertNull($copy->assignment->fresh()->source_training_plan_id);
        $this->assertCount(2, $copy->fresh()->trainingDays);
        $template = $this->template();
        $before = TrainingPlan::count();
        $assignments = $this->relationship->assignments()->count();
        $this->mock(ExerciseLibrary::class)->shouldReceive('copyMedia')->once()->andThrow(new \RuntimeException('Copy unavailable'));
        try {
            app(TrainingPlanDuplicator::class)->assign($this->coach, $this->trainee, $template, []);
            $this->fail('Copy failure must abort assignment.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Copy unavailable', $error->getMessage());
        }
        $this->assertSame($before, TrainingPlan::count());
        $this->assertSame($assignments, $this->relationship->assignments()->count());
    }
}
