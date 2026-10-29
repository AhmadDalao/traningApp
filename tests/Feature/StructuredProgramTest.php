<?php

namespace Tests\Feature;

use App\Models\CoachTraineeRelationship;
use App\Models\ProgramRun;
use App\Models\TrainingPlan;
use App\Models\User;
use App\Services\ActiveWorkout;
use App\Services\ExerciseLibrary;
use App\Services\ExerciseProgress;
use App\Services\ExerciseProgression;
use App\Services\PlanRecommendations;
use App\Services\ProgramDefinition;
use App\Services\ProgramLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StructuredProgramTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');
        $this->travelTo(now()->setDate(2026, 11, 1)->setTime(12, 0));
    }

    private function program(?User $owner = null): TrainingPlan
    {
        $owner ??= User::factory()->create();
        $plan = $owner->trainingPlans()->create(['name' => 'Two-week program', 'structure_type' => 'program', 'duration_weeks' => 2]);
        foreach ([[1, 'Foundation', 'normal', 3, '8-10', 40], [2, 'Deload', 'deload', 2, '10-12', 30]] as [$week, $name, $type, $sets, $reps, $weight]) {
            $block = $plan->blocks()->create(['name' => $name, 'start_week' => $week, 'end_week' => $week, 'type' => $type, 'position' => $week - 1]);
            $day = $plan->trainingDays()->create(['training_block_id' => $block->id, 'title' => 'Upper '.$name, 'type' => 'training', 'weekdays' => [1, 3, 5], 'default_time' => '18:00', 'position' => $week]);
            $day->exercises()->create(['name' => 'Bench press', 'sets' => $sets, 'reps' => $reps, 'target_weight' => $weight, 'tracking_mode' => 'reps', 'library_key' => 'builtin:Barbell_Bench_Press_-_Medium_Grip', 'superset_group' => 'A', 'position' => 0]);
            $plan->trainingDays()->create(['training_block_id' => $block->id, 'title' => 'Rest', 'type' => 'rest', 'weekdays' => [6], 'position' => $week + 10]);
        }

        return $plan;
    }

    private function start(TrainingPlan $plan, string $prefix = '', ?User $actor = null): ProgramRun
    {
        $this->actingAs($actor ?? $plan->user);
        $data = ['start_date' => '2026-11-02', 'nonce' => (string) Str::uuid()];
        $base = $prefix.'/training-plans/'.$plan->id;
        $preview = $this->postJson($base.'/program-preview', $data)->assertOk()->json('preview');
        $this->assertCount(8, $preview['sessions']);
        $this->postJson($base.'/program-start', [...$data, 'confirmed' => true, 'signature' => $preview['signature']])->assertOk();

        return $plan->programRuns()->firstOrFail();
    }

    private function rows(TrainingPlan $plan): array
    {
        return $plan->blocks()->get()->map(fn ($b) => $b->only('id', 'name', 'start_week', 'end_week', 'type', 'description'))->all();
    }

    public function test_creation_is_optional_finite_and_does_not_convert_existing_plans_or_schedule(): void
    {
        $user = User::factory()->create();
        $simple = $user->trainingPlans()->create(['name' => 'Existing']);
        $this->actingAs($user)->post('/training-plans', ['name' => 'Twelve weeks', 'structure_type' => 'program', 'duration_weeks' => 12, 'schedule_mode' => 'ongoing'])->assertSessionHasNoErrors();
        $program = $user->trainingPlans()->latest('id')->first();
        $this->assertSame('simple', $simple->fresh()->structure_type);
        $this->assertSame('fixed', $program->schedule_mode);
        $this->assertSame(12, $program->blocks->first()->end_week);
        $this->assertDatabaseCount('program_runs', 0);
        $this->assertDatabaseCount('schedule_entries', 0);
        foreach ([0, 53] as $duration) {
            $this->post('/training-plans', ['name' => 'Invalid', 'structure_type' => 'program', 'duration_weeks' => $duration])->assertSessionHasErrors('duration_weeks');
        }
        $this->put('/training-plans/'.$simple->id, ['name' => 'Existing', 'structure_type' => 'program', 'duration_weeks' => 12])->assertSessionHasNoErrors();
        $this->assertSame('simple', $simple->fresh()->structure_type);
        $this->get('/training-plans/'.$program->id.'/schedule-preview')->assertStatus(422);
    }

    public function test_atomic_blocks_reject_overlap_gap_out_of_range_foreign_ids_and_unsafe_deletion(): void
    {
        $plan = $this->program();
        $this->actingAs($plan->user);
        $original = $this->rows($plan);
        foreach ([[1, 1], [3, 3], [2, 3]] as [$start, $end]) {
            $rows = $original;
            $rows[1]['start_week'] = $start;
            $rows[1]['end_week'] = $end;
            $this->put('/training-plans/'.$plan->id.'/blocks', ['duration_weeks' => 2, 'blocks' => $rows])->assertSessionHasErrors('blocks');
            $this->assertEquals($original, $this->rows($plan));
        }
        $this->put('/training-plans/'.$plan->id.'/blocks', ['duration_weeks' => 2, 'blocks' => [$original[0]]])->assertSessionHasErrors('blocks');
        $rows = $original;
        $rows[1]['id'] = $this->program()->blocks->last()->id;
        $this->put('/training-plans/'.$plan->id.'/blocks', ['duration_weeks' => 2, 'blocks' => $rows])->assertNotFound();
        $rows = [];
        foreach ([[1, 3], [4, 7], [8, 11], [12, 12]] as $i => [$start, $end]) {
            $rows[] = ['name' => 'Block '.$i, 'start_week' => $start, 'end_week' => $end, 'type' => $i === 3 ? 'deload' : 'normal'];
        }
        $this->put('/training-plans/'.$plan->id.'/blocks', ['duration_weeks' => 12, 'blocks' => $rows])->assertSessionHasNoErrors();
        $this->assertCount(4, $plan->fresh()->blocks);
    }

    public function test_duplicate_block_and_plan_copy_days_media_identity_and_prescriptions_independently(): void
    {
        $plan = $this->program();
        $source = $plan->trainingDays->first()->exercises->first();
        $path = UploadedFile::fake()->image('bench.jpg')->store('exercise-thumbnails', 'public');
        $source->update(['thumbnail_path' => $path]);
        $rows = $this->rows($plan);
        $rows[] = ['name' => 'Copied foundation', 'start_week' => 3, 'end_week' => 3, 'type' => 'normal', 'copy_from' => $rows[0]['id']];
        $this->actingAs($plan->user)->put('/training-plans/'.$plan->id.'/blocks', ['duration_weeks' => 3, 'blocks' => $rows])->assertSessionHasNoErrors();
        $copy = $plan->fresh()->blocks->last()->trainingDays->first()->exercises->first();
        $this->assertSame($source->library_key, $copy->library_key);
        $this->assertSame('A', $copy->superset_group);
        $this->assertNotSame($source->thumbnail_path, $copy->thumbnail_path);
        $copy->update(['sets' => 8]);
        $this->assertSame(3, $source->fresh()->sets);
        $this->post('/training-plans/'.$plan->id.'/duplicate', ['confirmed' => true])->assertSessionHasNoErrors();
        $duplicate = $plan->user->trainingPlans()->latest('id')->first();
        $this->assertCount(3, $duplicate->blocks);
        $this->assertCount(6, $duplicate->trainingDays);
        $this->assertDatabaseCount('program_runs', 0);
        $this->assertDatabaseCount('schedule_entries', 0);
    }

    public function test_start_preview_is_required_idempotent_and_preserves_occurrence_and_block_prescriptions(): void
    {
        $plan = $this->program();
        $this->actingAs($plan->user);
        $base = '/training-plans/'.$plan->id;
        $data = ['start_date' => '2026-11-02', 'nonce' => (string) Str::uuid()];
        $this->postJson($base.'/program-start', $data)->assertUnprocessable();
        $preview = $this->postJson($base.'/program-preview', $data)->assertOk()->json('preview');
        $this->assertDatabaseCount('program_runs', 0);
        foreach ([1, 2] as $attempt) {
            $this->postJson($base.'/program-start', [...$data, 'confirmed' => true, 'signature' => $preview['signature']])->assertOk();
        }
        $this->assertDatabaseCount('program_runs', 1);
        $this->assertDatabaseCount('schedule_entries', 8);
        $entries = $plan->programRuns->first()->sessions;
        $this->assertCount(8, $entries->pluck('plan_occurrence_key')->unique());
        $this->assertSame('2026-11-02', app(ProgramLifecycle::class)->position($plan->programRuns->first())['next_workout']['scheduled_date']);
        $this->assertSame('2026-11-02', app(ProgramLifecycle::class)->position($plan->programRuns->first())['sessions'][0]['scheduled_date']);
        $this->assertSame(3, $entries->where('program_week', 1)->first()->program_prescription['exercises'][0]['sets']);
        $this->assertSame(2, $entries->where('program_week', 2)->first()->program_prescription['exercises'][0]['sets']);
        $this->assertSame('deload', $entries->where('program_week', 2)->first()->program_context['block_type']);
    }

    public function test_stale_start_and_empty_training_blocks_are_rejected_without_partial_runs(): void
    {
        $plan = $this->program();
        $this->actingAs($plan->user);
        $data = ['start_date' => '2026-11-02', 'nonce' => (string) Str::uuid()];
        $base = '/training-plans/'.$plan->id;
        $signature = $this->postJson($base.'/program-preview', $data)->json('preview.signature');
        $plan->trainingDays->first()->exercises->first()->update(['sets' => 5]);
        $this->postJson($base.'/program-start', [...$data, 'signature' => $signature, 'confirmed' => true])->assertUnprocessable();
        $plan->trainingDays()->where('type', 'training')->update(['weekdays' => '[]']);
        $this->postJson($base.'/program-preview', $data)->assertUnprocessable();
        $this->assertDatabaseCount('program_runs', 0);
        $this->assertDatabaseCount('schedule_entries', 0);
    }

    public function test_progression_is_logical_missed_does_not_advance_and_rest_never_blocks(): void
    {
        $run = $this->start($this->program());
        $service = app(ProgramLifecycle::class);
        $this->travelTo(now()->addDays(20));
        $this->assertSame(1, $service->position($run)['current_week']);
        $this->assertSame(6, $service->position($run)['missed']);
        $this->assertSame('planned', $run->sessions->first()->status);
        $second = $run->sessions()->where('program_week', 2)->where('entry_type', 'training')->first();
        $this->patch('/schedule-entries/'.$second->id.'/status', ['status' => 'completed'])->assertSessionHasNoErrors();
        $this->assertSame(1, $service->position($run)['current_week']);
        $firstWeek = $run->sessions()->where('program_week', 1)->where('entry_type', 'training')->get();
        foreach ($firstWeek as $i => $entry) {
            $this->patch('/schedule-entries/'.$entry->id.'/status', ['status' => $i === 2 ? 'skipped' : 'completed'])->assertSessionHasNoErrors();
            $this->assertSame($i === 2 ? 2 : 1, $service->position($run)['current_week']);
        }
        $this->assertSame('Deload', $service->position($run)['current_block']['name']);
        $this->assertSame(2, $run->sessions()->where('entry_type', 'rest')->where('status', 'planned')->count());
    }

    public function test_missed_program_session_can_move_individually_without_changing_logical_week_or_identity(): void
    {
        $run = $this->start($this->program());
        $entry = $run->sessions->first();
        $this->travelTo(now()->addDays(10));
        $data = ['scheduled_date' => today()->addDays(5)->toDateString(), 'scheduled_time' => '18:00', 'scope' => 'one'];
        $signature = $this->postJson('/schedule-entries/'.$entry->id.'/reschedule-preview', $data)->assertOk()->json('preview.signature');
        $this->postJson('/schedule-entries/'.$entry->id.'/reschedule', [...$data, 'signature' => $signature, 'confirmed' => true])->assertOk();
        $this->assertSame(1, $entry->fresh()->program_week);
        $this->assertSame($entry->plan_occurrence_key, $entry->fresh()->plan_occurrence_key);
        $this->assertSame(8, $run->sessions()->count());
        $this->postJson('/schedule-entries/'.$entry->id.'/reschedule-preview', [...$data, 'scope' => 'future', 'scheduled_date' => today()->addDays(6)->toDateString()])->assertForbidden();
    }

    public function test_prescriptions_freeze_at_run_start_and_active_workouts_use_same_structured_engine(): void
    {
        $plan = $this->program();
        $run = $this->start($plan);
        $entry = $run->sessions->first();
        $source = $plan->trainingDays->first()->exercises->first();
        $source->update(['sets' => 9, 'target_weight' => 100]);
        $this->assertSame(3, $entry->fresh()->workoutDetails()['exercises'][0]['sets']);
        $this->post('/schedule-entries/'.$entry->id.'/workout/start')->assertRedirect();
        $exercise = $entry->sessionExercises()->first();
        $this->assertSame(3, $exercise->planned_sets);
        $this->assertEquals(40, $exercise->planned_weight_kg);
        $this->assertSame($source->id, $exercise->source_training_exercise_id);
        $source->update(['sets' => 5]);
        $this->assertSame(3, $exercise->fresh()->sets()->count());
        $this->assertSame($source->library_key, $exercise->source_exercise_key);
        $this->post('/schedule-entries/'.$entry->id.'/workout/finish', ['confirm_incomplete' => true])->assertRedirect();
        $this->assertSame('completed', $entry->fresh()->status);
    }

    public function test_future_changes_require_preview_protect_history_active_and_individual_moves_and_reject_stale(): void
    {
        $plan = $this->program();
        $run = $this->start($plan);
        $first = $run->sessions->first();
        $this->patch('/schedule-entries/'.$first->id.'/status', ['status' => 'completed'])->assertSessionHasNoErrors();
        $snapshot = $first->fresh()->workout_snapshot;
        $other = $run->sessions()->where('entry_type', 'training')->where('program_week', 2)->first();
        $other->update(['schedule_managed' => false]);
        foreach ($plan->trainingDays as $day) {
            $day->exercises()->update(['sets' => 7]);
        }
        $url = '/program-runs/'.$run->id;
        $preview = $this->postJson($url.'/change-preview')->assertOk()->json('preview');
        $this->assertCount(4, $preview['update']);
        $this->assertCount(2, $preview['protected']);
        $changing = $run->sessions()->where('entry_type', 'training')->whereKeyNot($first->id)->whereKeyNot($other->id)->first();
        $changing->update(['started_at' => now()]);
        $this->postJson($url.'/changes', ['signature' => $preview['signature'], 'confirmed' => true])->assertUnprocessable();
        $preview = $this->postJson($url.'/change-preview')->json('preview');
        $this->postJson($url.'/changes', ['signature' => $preview['signature'], 'confirmed' => true])->assertOk();
        $this->assertEquals($snapshot, $first->fresh()->workout_snapshot);
        $this->assertSame(2, $other->fresh()->program_prescription['exercises'][0]['sets']);
        $this->assertSame(3, $changing->fresh()->program_prescription['exercises'][0]['sets']);
        $updated = $run->sessions()->where('entry_type', 'training')->where('program_week', 2)->whereKeyNot($other->id)->first();
        $this->assertSame(7, $updated->program_prescription['exercises'][0]['sets']);
    }

    public function test_adherence_excludes_future_and_completion_reopen_repeat_preserve_old_run(): void
    {
        $plan = $this->program();
        $run = $this->start($plan);
        $this->travelTo(now()->setDate(2026, 11, 7));
        $first = $run->sessions->first();
        $second = $run->sessions->where('entry_type', 'training')->values()[1];
        $this->patch('/schedule-entries/'.$first->id.'/status', ['status' => 'completed']);
        $this->patch('/schedule-entries/'.$second->id.'/status', ['status' => 'skipped']);
        $position = app(ProgramLifecycle::class)->position($run);
        $this->assertSame(3, $position['due']);
        $this->assertSame(33.0, $position['adherence']);
        $this->assertSame(1, $position['missed']);
        foreach ($run->sessions()->where('entry_type', 'training')->get() as $entry) {
            $this->patch('/schedule-entries/'.$entry->id.'/status', ['status' => 'completed']);
        }
        $this->get('/program-runs/'.$run->id)->assertInertia(fn (Assert $p) => $p->where('run.status', 'completed')->where('summary.pr_count', 0)->where('summary.body_weight_change', null));
        $completed = $run->fresh()->completed_at;
        $this->assertNotNull($completed);
        $this->patch('/schedule-entries/'.$first->id.'/status', ['status' => 'planned']);
        $this->get('/program-runs/'.$run->id)->assertInertia(fn (Assert $p) => $p->where('run.status', 'active')->where('position.current_week', 1));
        $this->patch('/schedule-entries/'.$first->id.'/status', ['status' => 'completed']);
        $this->get('/program-runs/'.$run->id)->assertOk();
        $original = $run->fresh()->definition_snapshot;
        $plan->update(['name' => 'Renamed future program']);
        $this->travelBack();
        $this->travelTo(now()->setDate(2026, 11, 1));
        $newRun = $this->start($plan);
        $this->assertNotSame($run->id, $newRun->id);
        $this->assertEquals($original, $run->fresh()->definition_snapshot);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertDatabaseCount('schedule_entries', 16);
    }

    public function test_program_deletion_retains_run_context_saved_media_and_workouts(): void
    {
        $plan = $this->program();
        $path = UploadedFile::fake()->image('bench.jpg')->store('exercise-thumbnails', 'public');
        $plan->trainingDays->first()->exercises->first()->update(['thumbnail_path' => $path]);
        $run = $this->start($plan);
        $entry = $run->sessions->first();
        $savedPath = $entry->program_prescription['exercises'][0]['thumbnail_path'];
        $this->assertNotSame($path, $savedPath);
        $this->delete('/schedule-entries/'.$entry->id)->assertStatus(422);
        $this->delete('/training-plans/'.$plan->id)->assertRedirect();
        $this->assertNull($run->fresh()->training_plan_id);
        $this->get('/program-runs/'.$run->id)->assertOk();
        Storage::disk('public')->assertExists($savedPath);
        $this->post('/schedule-entries/'.$entry->id.'/workout/start')->assertRedirect();
        $this->assertSame(3, $entry->sessionExercises()->first()->planned_sets);
    }

    public function test_foreign_program_block_day_and_run_ids_are_denied(): void
    {
        $plan = $this->program();
        $run = $this->start($plan);
        $this->actingAs(User::factory()->create());
        foreach (['/training-plans/'.$plan->id, '/training-plans/'.$plan->id.'/program-start', '/program-runs/'.$run->id] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->put('/training-plans/'.$plan->id.'/blocks', ['duration_weeks' => 2, 'blocks' => $this->rows($plan)])->assertForbidden();
        $this->postJson('/program-runs/'.$run->id.'/change-preview')->assertForbidden();
        $this->postJson('/training-plans/'.$plan->id.'/program-preview', ['start_date' => '2026-11-02', 'nonce' => (string) Str::uuid()])->assertForbidden();
        $own = $this->program(auth()->user());
        $this->post('/training-plans/'.$own->id.'/days', ['training_block_id' => $plan->blocks->first()->id, 'title' => 'Foreign', 'type' => 'rest'])->assertSessionHasErrors('training_block_id');
    }

    public function test_four_program_catalog_entries_have_complete_blocks_real_library_keys_and_no_implicit_start(): void
    {
        $user = User::factory()->create();
        $programs = collect(app(PlanRecommendations::class)->catalog())->where('structure_type', 'program');
        $this->assertCount(4, $programs);
        foreach ($programs as $preset) {
            $this->actingAs($user)->get('/recommendations/'.$preset['key'])->assertOk();
            $this->post('/recommendations', ['preset' => $preset['key']])->assertSessionHasNoErrors();
            $plan = $user->trainingPlans()->latest('id')->first();
            $this->assertSame('program', $plan->structure_type);
            $this->assertCount(count($preset['blocks']), $plan->blocks);
            app(ProgramDefinition::class)->validate($this->rows($plan), $plan->duration_weeks);
            foreach ($plan->trainingDays as $day) {
                $this->assertNotEmpty($day->weekdays);
                foreach ($day->exercises as $exercise) {
                    $this->assertStringStartsWith('builtin:', $exercise->library_key);
                    Storage::disk('public')->assertExists($exercise->thumbnail_path);
                }
            }
        }
        $this->assertDatabaseCount('program_runs', 0);
        $this->assertDatabaseCount('schedule_entries', 0);
    }

    public function test_coach_assignment_start_future_edit_monitor_disconnect_and_subscription_rules(): void
    {
        $coach = User::factory()->create();
        $coach->forceFill(['account_type' => 'coach'])->save();
        $coach->coachProfile()->create(['display_name' => 'Coach']);
        $trainee = User::factory()->create();
        $relationship = CoachTraineeRelationship::create(['coach_user_id' => $coach->id, 'trainee_user_id' => $trainee->id, 'status' => 'active', 'requested_by' => $coach->id, 'requested_at' => now(), 'approved_at' => now()]);
        $subscription = $relationship->subscriptions()->create(['package_name' => 'Program coaching', 'starts_on' => today(), 'ends_on' => today()->addDays(60), 'status' => 'active']);
        $template = $this->program($coach);
        $template->forceFill(['workspace_type' => 'coach_template'])->save();
        $base = '/coach/trainees/'.$trainee->id;
        $this->actingAs($coach)->post($base.'/assign', ['training_plan_id' => $template->id, 'confirmed' => true])->assertSessionHasNoErrors();
        $copy = $trainee->trainingPlans()->first();
        $this->assertCount(2, $copy->blocks);
        $this->assertDatabaseCount('program_runs', 0);
        $template->trainingDays->first()->exercises->first()->update(['sets' => 9]);
        $this->assertSame(3, $copy->trainingDays->first()->exercises->first()->sets);
        $run = $this->start($copy, $base, $coach);
        $this->assertSame($trainee->id, $run->user_id);
        $this->assertSame($coach->id, $run->sessions->first()->scheduled_by_user_id);
        $currentExercise = $copy->trainingDays->first()->exercises->first();
        $this->patch($base.'/training-exercises/'.$currentExercise->id.'/progression', ['token' => str_repeat('a', 64), 'value' => '45'])->assertForbidden();
        $this->assertEquals(40, $currentExercise->fresh()->target_weight);
        $rows = $this->rows($copy);
        $rows[0]['name'] = 'Cannot change current';
        $this->put($base.'/training-plans/'.$copy->id.'/blocks', ['duration_weeks' => 2, 'blocks' => $rows])->assertForbidden();
        $rows = $this->rows($copy);
        $rows[1]['name'] = 'Updated future';
        $this->put($base.'/training-plans/'.$copy->id.'/blocks', ['duration_weeks' => 2, 'blocks' => $rows])->assertSessionHasNoErrors();
        $preview = $this->postJson($base.'/program-runs/'.$run->id.'/change-preview')->assertOk()->json('preview');
        $this->postJson($base.'/program-runs/'.$run->id.'/changes', ['confirmed' => true, 'signature' => $preview['signature']])->assertOk();
        $entry = $run->sessions->first();
        $this->post('/schedule-entries/'.$entry->id.'/workout/start')->assertForbidden();
        $this->patch('/schedule-entries/'.$entry->id.'/status', ['status' => 'completed'])->assertForbidden();
        $this->get($base.'/program-runs/'.$run->id)->assertOk();
        $personal = $this->program($trainee);
        $this->get($base.'/training-plans/'.$personal->id.'/program-start')->assertForbidden();
        $subscription->update(['status' => 'paused']);
        $this->postJson($base.'/program-runs/'.$run->id.'/change-preview')->assertForbidden();
        $this->get($base.'/program-runs/'.$run->id)->assertOk();
        $relationship->update(['status' => 'ended']);
        $this->get($base.'/program-runs/'.$run->id)->assertNotFound();
        $this->actingAs($trainee)->get('/program-runs/'.$run->id)->assertOk();
        $this->assertDatabaseCount('schedule_entries', 8);
    }

    public function test_future_schedule_diff_adds_and_removes_only_reviewed_occurrences(): void
    {
        $plan = $this->program();
        $run = $this->start($plan);
        $day = $plan->trainingDays()->where('type', 'training')->where('training_block_id', $plan->blocks->last()->id)->first();
        $day->update(['weekdays' => [0, 2]]);
        $preview = $this->postJson('/program-runs/'.$run->id.'/change-preview')->assertOk()->json('preview');
        $this->assertCount(2, $preview['add']);
        $this->assertCount(3, $preview['remove']);
        $this->assertSame(8, $run->sessions()->count());
        $this->postJson('/program-runs/'.$run->id.'/changes', ['signature' => $preview['signature'], 'confirmed' => true])->assertOk();
        $this->assertSame(7, $run->sessions()->count());
        $this->assertSame(2, $run->sessions()->where('program_week', 2)->where('entry_type', 'training')->count());
        $again = $this->postJson('/program-runs/'.$run->id.'/change-preview')->assertOk()->json('preview');
        $this->assertCount(0, $again['add']);
        $this->assertCount(0, $again['remove']);
        $this->assertCount(0, $again['update']);
    }

    public function test_block_overload_targets_only_selected_exercise_and_global_records_ignore_zero_weight(): void
    {
        $plan = $this->program();
        $run = $this->start($plan);
        $service = app(ActiveWorkout::class);
        $entries = $run->sessions()->where('entry_type', 'training')->where('program_week', 1)->get();
        foreach ($entries as $entry) {
            $service->start($entry);
            $movement = $entry->sessionExercises()->first();
            $movement->sets()->update(['actual_reps' => 10, 'actual_weight_kg' => 40, 'completed_at' => now()]);
            $service->finish($entry, true);
        }
        $source = $plan->trainingDays->first()->exercises->first();
        $other = $plan->trainingDays()->where('type', 'training')->where('training_block_id', $plan->blocks->last()->id)->first()->exercises->first();
        $suggestion = app(ExerciseProgression::class)->forTemplate($plan->user, $source);
        $this->assertTrue($suggestion['can_apply']);
        $this->patch('/training-exercises/'.$source->id.'/progression', ['token' => $suggestion['token'], 'value' => 42.5])->assertSessionHasNoErrors();
        $this->assertEquals(42.5, $source->fresh()->target_weight);
        $this->assertEquals(30, $other->fresh()->target_weight);
        $this->assertEquals(40, $entries->first()->fresh()->workout_snapshot['exercises'][0]['target_weight']);
        foreach ($run->sessions()->where('entry_type', 'training')->where('program_week', 2)->get() as $i => $entry) {
            $service->start($entry);
            $entry->sessionExercises()->first()->sets()->update(['actual_reps' => 20, 'actual_weight_kg' => $i === 1 ? null : 0, 'completed_at' => now()]);
            $service->finish($entry, true);
        }
        $progress = app(ExerciseProgress::class);
        $rows = $progress->workouts($plan->user, $source->library_key);
        $this->assertCount(6, $rows);
        $this->assertSame(20, $rows->last()['max_reps']);
        $this->assertNull($rows->last()['heaviest_weight']);
        $this->assertNull($rows->last()['weighted_volume']);
        $run = app(ProgramLifecycle::class)->sync($run->id);
        $summary = app(ProgramLifecycle::class)->summary($run);
        $this->assertGreaterThan(0, $summary['pr_count']);
        $this->assertCount(0, $summary['weight_gains']);
        $last = $run->sessions()->where('entry_type', 'training')->where('program_week', 2)->first();
        $this->patch('/schedule-entries/'.$last->id.'/status', ['status' => 'planned']);
        $this->assertCount(5, $progress->workouts($plan->user, $source->library_key));
    }

    public function test_completion_body_weight_uses_historical_entries_and_does_not_fabricate_profile_values(): void
    {
        $plan = $this->program();
        $user = $plan->user;
        $user->bodyWeightEntries()->create(['recorded_date' => '2026-11-01', 'weight_kg' => 80]);
        $run = $this->start($plan);
        $this->travelTo(now()->setDate(2026, 11, 16));
        $user->bodyWeightEntries()->create(['recorded_date' => '2026-11-15', 'weight_kg' => 79]);
        $user->bodyWeightEntries()->create(['recorded_date' => '2026-11-20', 'weight_kg' => 75]);
        foreach ($run->sessions()->where('entry_type', 'training')->get() as $entry) {
            $this->patch('/schedule-entries/'.$entry->id.'/status', ['status' => 'skipped']);
        }
        $run = app(ProgramLifecycle::class)->sync($run->id);
        $this->assertSame('completed', $run->status);
        $this->assertSame(0.0, app(ProgramLifecycle::class)->position($run)['adherence']);
        $this->assertEquals(-1, app(ProgramLifecycle::class)->summary($run)['body_weight_change']);
        $user->bodyWeightEntries()->delete();
        $user->profile()->create(['current_weight_kg' => 90]);
        $this->assertNull(app(ProgramLifecycle::class)->summary($run)['body_weight_change']);
    }

    public function test_reordering_blocks_reflows_ranges_without_sharing_prescriptions_and_requires_owned_day_block(): void
    {
        $plan = $this->program();
        $rows = array_reverse($this->rows($plan));
        foreach ($rows as $i => &$row) {
            $row['start_week'] = $row['end_week'] = $i + 1;
        }
        unset($row);
        $this->actingAs($plan->user)->put('/training-plans/'.$plan->id.'/blocks', ['duration_weeks' => 2, 'blocks' => $rows])->assertSessionHasNoErrors();
        $this->assertSame('Deload', $plan->fresh()->blocks->first()->name);
        $this->post('/training-plans/'.$plan->id.'/days', ['title' => 'Unassigned', 'type' => 'rest'])->assertSessionHasErrors('training_block_id');
        $this->get('/training-plans/'.$plan->id.'/days/create?block='.$plan->blocks->first()->id)->assertInertia(fn (Assert $p) => $p->has('plan.blocks', 2));
    }

    public function test_start_rolls_back_every_record_and_new_media_if_copying_fails(): void
    {
        $plan = $this->program();
        $this->actingAs($plan->user);
        $data = ['start_date' => '2026-11-02', 'nonce' => (string) Str::uuid()];
        $base = '/training-plans/'.$plan->id;
        $signature = $this->postJson($base.'/program-preview', $data)->assertOk()->json('preview.signature');
        $calls = 0;
        $this->mock(ExerciseLibrary::class, function ($mock) use (&$calls) {
            $mock->shouldReceive('copyMedia')->andReturnUsing(function ($source, $field, $directory, &$paths) use (&$calls) {
                if (++$calls === 2) {
                    throw new \RuntimeException('Simulated file-copy failure');
                }
                $paths[] = 'program-media/failed-copy.jpg';
                Storage::disk('public')->put('program-media/failed-copy.jpg', 'temporary');

                return 'program-media/failed-copy.jpg';
            });
        });
        $this->postJson($base.'/program-start', [...$data, 'signature' => $signature, 'confirmed' => true])->assertStatus(500);
        $this->assertDatabaseCount('program_runs', 0);
        $this->assertDatabaseCount('schedule_entries', 0);
        Storage::disk('public')->assertMissing('program-media/failed-copy.jpg');
    }
}
