<?php

namespace Tests\Feature;

use App\Models\CoachTraineeRelationship;
use App\Models\ProgramRun;
use App\Models\ScheduleEntry;
use App\Models\TrainingPlan;
use App\Models\User;
use App\Services\ActiveWorkout;
use App\Services\ExerciseProgress;
use App\Services\ExerciseProgression;
use App\Services\ProgramDefinition;
use App\Services\ProgramLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProgramLifecycleStabilizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');
        $this->travelTo(now()->setDate(2026, 11, 1)->setTime(12, 0));
    }

    private function program(?User $user = null, int $weeks = 4): TrainingPlan
    {
        $user ??= User::factory()->create();
        $plan = $user->trainingPlans()->create(['name' => 'Lifecycle QA', 'structure_type' => 'program', 'duration_weeks' => $weeks]);
        foreach (['Foundation', 'Build', 'Progress', 'Deload'] as $i => $name) {
            $block = $plan->blocks()->create(['name' => $name, 'start_week' => $i * ($weeks / 4) + 1, 'end_week' => ($i + 1) * ($weeks / 4), 'position' => $i, 'type' => $i === 3 ? 'deload' : 'normal']);
            $day = $plan->trainingDays()->create(['training_block_id' => $block->id, 'title' => $name.' workout', 'type' => 'training', 'weekdays' => [1, 3, 5], 'default_time' => '18:00', 'position' => $i]);
            $day->exercises()->create(['name' => 'Bench press', 'sets' => $i === 3 ? 1 : 2, 'reps' => '8-10', 'target_weight' => $i === 3 ? 25 : 40, 'tracking_mode' => 'reps', 'library_key' => 'builtin:Barbell_Bench_Press_-_Medium_Grip', 'superset_group' => 'A', 'position' => 0, 'notes' => 'Original form cues']);
        }

        return $plan;
    }

    private function start(TrainingPlan $plan, string $date = '2026-11-02', ?User $coach = null): ProgramRun
    {
        $this->actingAs($coach ?? $plan->user);
        $prefix = $coach ? '/coach/trainees/'.$plan->user_id : '';
        $base = $prefix.'/training-plans/'.$plan->id;
        $data = ['start_date' => $date, 'nonce' => (string) Str::uuid()];
        $preview = $this->postJson($base.'/program-preview', $data)->assertOk()->json('preview');
        $url = $this->postJson($base.'/program-start', [...$data, 'signature' => $preview['signature'], 'confirmed' => true])->assertOk()->json('url');

        return ProgramRun::findOrFail(basename($url));
    }

    private function coaching(): array
    {
        $coach = User::factory()->create(['account_type' => 'coach']);
        $coach->coachProfile()->create(['display_name' => 'Lifecycle coach']);
        $trainee = User::factory()->create();
        $relationship = CoachTraineeRelationship::create(['coach_user_id' => $coach->id, 'trainee_user_id' => $trainee->id, 'status' => 'active', 'requested_by' => $coach->id, 'requested_at' => now(), 'approved_at' => now()]);
        $subscription = $relationship->subscriptions()->create(['package_name' => 'QA coaching', 'starts_on' => today(), 'ends_on' => today()->addDays(120), 'status' => 'active']);
        $template = $this->program($coach);
        $template->forceFill(['workspace_type' => 'coach_template'])->save();
        $base = '/coach/trainees/'.$trainee->id;
        $this->actingAs($coach)->post($base.'/assign', ['training_plan_id' => $template->id, 'confirmed' => true])->assertSessionHasNoErrors();
        $plan = $trainee->trainingPlans()->firstOrFail();
        $run = $this->start($plan, coach: $coach);

        return [$coach, $trainee, $plan, $run, $base, $relationship, $subscription];
    }

    private function resolve(ProgramRun $run, int $week, string $status = 'completed'): void
    {
        foreach ($run->sessions()->where('program_week', $week)->where('entry_type', 'training')->get() as $entry) {
            $entry->update(['status' => $status, 'completed_at' => $status === 'completed' ? now() : null]);
        }
        app(ProgramLifecycle::class)->sync($run->id);
    }

    private function record(ScheduleEntry $entry, int $reps = 10, ?float $weight = 40): void
    {
        $workout = app(ActiveWorkout::class);
        $entry = $workout->start($entry);
        foreach ($entry->sessionExercises as $exercise) {
            $exercise->sets()->update(['actual_reps' => $reps, 'actual_weight_kg' => $weight, 'completed_at' => now()]);
        }
        $workout->finish($entry, true);
    }

    public function test_protected_preview_uses_saved_title_context_and_moved_date(): void
    {
        $plan = $this->program();
        $run = $this->start($plan);
        $entry = $run->sessions()->where('program_week', 2)->first();
        $entry->update(['scheduled_date' => '2027-01-10', 'title' => 'My moved workout', 'schedule_managed' => false]);
        $entry->trainingDay->update(['title' => 'New definition title']);
        $entry->trainingDay->trainingBlock->update(['name' => 'New block name']);
        $preview = $this->postJson('/program-runs/'.$run->id.'/change-preview')->assertOk()->json('preview');
        $row = collect($preview['protected'])->firstWhere('id', $entry->id);
        $this->assertSame('2027-01-10', $row['scheduled_date']);
        $this->assertSame('My moved workout', $row['title']);
        $this->assertSame($entry->program_context, $row['program_context']);
    }

    public function test_coach_day_order_endpoint_cannot_modify_current_block(): void
    {
        [$coach, $trainee, $plan, $run, $base] = $this->coaching();
        $days = $plan->trainingDays()->get();
        $before = $days->pluck('position', 'id')->all();
        $this->patch($base.'/training-plans/'.$plan->id.'/days/reorder', ['ids' => $days->pluck('id')->reverse()->values()->all()])->assertForbidden();
        $this->assertSame($before, $plan->trainingDays()->get()->pluck('position', 'id')->all());
    }

    public function test_coach_cannot_edit_an_out_of_order_completed_block(): void
    {
        [$coach, $trainee, $plan, $run, $base] = $this->coaching();
        $this->resolve($run, 3);
        $this->assertSame(1, app(ProgramLifecycle::class)->position($run)['current_week']);
        $block = $plan->blocks()->where('start_week', 3)->first();
        $this->assertFalse(app(ProgramDefinition::class)->coachCanEditBlock($block));
        $day = $block->trainingDays->first();
        $this->delete($base.'/training-days/'.$day->id)->assertForbidden();
        $this->assertDatabaseHas('training_days', ['id' => $day->id]);
    }

    public function test_coach_diff_cannot_add_sessions_to_current_block_after_owner_definition_edit(): void
    {
        [$coach, $trainee, $plan, $run, $base] = $this->coaching();
        $day = $plan->trainingDays()->first();
        $copy = $day->replicate();
        $copy->title = 'Owner added day';
        $copy->save();
        $copy->exercises()->create($day->exercises->first()->only('name', 'sets', 'reps', 'target_weight', 'tracking_mode', 'library_key', 'position'));
        $preview = $this->postJson($base.'/program-runs/'.$run->id.'/change-preview')->assertOk()->json('preview');
        $this->assertCount(0, $preview['add']);
        $this->postJson($base.'/program-runs/'.$run->id.'/changes', ['signature' => $preview['signature'], 'confirmed' => true])->assertOk();
        $this->assertSame(12, $run->sessions()->count());
    }

    public function test_future_reconciliation_does_not_move_today_session_back_into_past(): void
    {
        $plan = $this->program();
        $run = $this->start($plan);
        $this->travelTo(now()->setDate(2026, 11, 9)->setTime(12, 0));
        $entry = $run->sessions()->where('scheduled_date', '2026-11-09')->firstOrFail();
        $entry->trainingDay->update(['default_time' => '09:00']);
        $preview = $this->postJson('/program-runs/'.$run->id.'/change-preview')->assertOk()->json('preview');
        $this->assertNotContains($entry->id, array_column($preview['update'], 'id'));
        $this->assertContains($entry->id, array_column($preview['protected'], 'id'));
        $this->postJson('/program-runs/'.$run->id.'/changes', ['signature' => $preview['signature'], 'confirmed' => true])->assertOk();
        $this->assertSame('18:00', substr($entry->fresh()->scheduled_time, 0, 5));
    }

    public function test_completion_summary_batches_exercise_metadata_queries(): void
    {
        $plan = $this->program();
        $day = $plan->trainingDays()->first();
        foreach (['builtin:Dumbbell_Bench_Press', 'builtin:Pushups'] as $i => $key) {
            $copy = $day->exercises->first()->replicate();
            $copy->library_key = $key;
            $copy->name = 'Long exercise '.$i;
            $copy->position = $i + 1;
            $copy->save();
        }
        $run = $this->start($plan);
        foreach ($run->sessions()->where('program_week', 1)->get() as $i => $entry) {
            $this->record($entry, weight: 40 + $i * 5);
        }
        DB::enableQueryLog();
        $summary = app(ProgramLifecycle::class)->summary($run);
        $queries = collect(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertCount(3, $summary['weight_gains']);
        $metadata = $queries->filter(fn ($q) => preg_match('/select \* from ["`]session_exercises["`]/i', $q['query']));
        $this->assertLessThanOrEqual(1, $metadata->count());
    }

    public function test_invalid_block_ranges_and_duration_changes_leave_no_orphans(): void
    {
        $plan = $this->program(weeks: 12);
        $this->actingAs($plan->user);
        $original = $plan->blocks->map->only('id', 'name', 'start_week', 'end_week', 'type')->all();
        foreach ([[0, 3], [-1, 3], [1, 53], [4, 3], [2, 3], [1, 4]] as [$start,$end]) {
            $rows = $original;
            $rows[0]['start_week'] = $start;
            $rows[0]['end_week'] = $end;
            $this->putJson('/training-plans/'.$plan->id.'/blocks', ['duration_weeks' => 12, 'blocks' => $rows])->assertUnprocessable();
            $this->assertSame($original, $plan->fresh()->blocks->map->only('id', 'name', 'start_week', 'end_week', 'type')->all());
        }
        $rows = $original;
        $rows[3]['end_week'] = 10;
        $this->put('/training-plans/'.$plan->id.'/blocks', ['duration_weeks' => 10, 'blocks' => $rows])->assertSessionHasNoErrors();
        $this->assertSame(10, $plan->fresh()->duration_weeks);
        $this->put('/training-plans/'.$plan->id.'/blocks', ['duration_weeks' => 12, 'blocks' => $original])->assertSessionHasNoErrors();
        $this->assertSame(12, $plan->fresh()->duration_weeks);
        $this->assertDatabaseCount('program_runs', 0);
        $this->assertSame(4, $plan->trainingDays()->count());
    }

    public function test_start_dates_preserve_calendar_days_across_month_year_and_leap_boundaries(): void
    {
        $plan = $this->program();
        foreach (['2026-11-01', '2026-11-30', '2026-12-31', '2028-02-29'] as $date) {
            $run = $this->start($plan, $date);
            $this->assertSame($date, $run->started_on->toDateString());
            $this->get('/program-runs/'.$run->id)->assertInertia(fn (Assert $page) => $page->where('run.started_on', $date));
            foreach ($run->sessions as $entry) {
                $offset = (int) $run->started_on->diffInDays($entry->scheduled_date);
                $this->assertSame((int) floor($offset / 7) + 1, $entry->program_week);
                $this->assertStringEndsWith($entry->scheduled_date->toDateString(), $entry->plan_occurrence_key);
            }
        }
        foreach (['2026-10-31', '2027-02-29', 'not-a-date'] as $date) {
            $this->postJson('/training-plans/'.$plan->id.'/program-preview', ['start_date' => $date, 'nonce' => (string) Str::uuid()])->assertUnprocessable();
        }
        $this->assertSame(4, $plan->programRuns()->count());
    }

    public function test_partial_missed_out_of_order_and_rest_weeks_advance_by_required_work_only(): void
    {
        $plan = $this->program();
        $plan->trainingDays()->create(['training_block_id' => $plan->blocks->first()->id, 'title' => 'Rest', 'type' => 'rest', 'weekdays' => [6], 'position' => 9]);
        $run = $this->start($plan);
        $service = app(ProgramLifecycle::class);
        $first = $run->sessions()->where('program_week', 1)->where('entry_type', 'training')->get();
        foreach ($first->take(2) as $entry) {
            $this->patch('/schedule-entries/'.$entry->id.'/status', ['status' => 'completed'])->assertSessionHasNoErrors();
            $this->assertSame(1, $service->position($run)['current_week']);
        }
        $this->travelTo(now()->setDate(2026, 11, 30));
        $this->assertSame(10, $service->position($run)['missed']);
        $this->assertSame('planned', $first->last()->fresh()->status);
        $this->resolve($run, 4);
        $this->assertSame(1, $service->position($run)['current_week']);
        $this->patch('/schedule-entries/'.$first->last()->id.'/status', ['status' => 'skipped'])->assertSessionHasNoErrors();
        $this->assertSame(2, $service->position($run)['current_week']);
        $this->resolve($run, 2);
        $this->assertSame(3, $service->position($run)['current_week']);
        $this->assertSame('Progress', $service->position($run)['current_block']['name']);
        $this->resolve($run, 3);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame('planned', $run->sessions()->where('entry_type', 'rest')->first()->status);
        $this->assertSame(1, $service->position($run)['skipped_workouts']);
        $this->assertSame(92.0, $service->position($run)['adherence']);
    }

    public function test_missed_forward_and_backward_moves_preserve_logical_week_and_occurrence(): void
    {
        $run = $this->start($this->program());
        $this->resolve($run, 1);
        $this->travelTo(now()->setDate(2026, 11, 16)->setTime(12, 0));
        $entry = $run->sessions()->where('program_week', 2)->first();
        $identity = $entry->only('program_run_id', 'program_week', 'program_context', 'plan_occurrence_key');
        foreach (['2027-01-15', '2026-11-17'] as $date) {
            $data = ['scheduled_date' => $date, 'scheduled_time' => '18:00', 'scope' => 'one'];
            $preview = $this->postJson('/schedule-entries/'.$entry->id.'/reschedule-preview', $data)->assertOk()->json('preview');
            $this->postJson('/schedule-entries/'.$entry->id.'/reschedule', [...$data, 'signature' => $preview['signature'], 'confirmed' => true])->assertOk();
            $this->assertSame($identity, $entry->fresh()->only(array_keys($identity)));
            $position = app(ProgramLifecycle::class)->position($run);
            $this->assertSame(2, $position['current_week']);
            $this->assertFalse(collect($position['sessions'])->firstWhere('id', $entry->id)['missed']);
            $this->assertSame(12, $run->sessions()->count());
        }
        $this->travelTo(now()->setDate(2026, 11, 18));
        $this->assertTrue(collect(app(ProgramLifecycle::class)->position($run)['sessions'])->firstWhere('id', $entry->id)['missed']);
    }

    public function test_reconciliation_is_atomic_after_stale_and_double_submissions(): void
    {
        $plan = $this->program();
        $run = $this->start($plan);
        $day = $plan->trainingDays()->where('position', 1)->first();
        $day->update(['weekdays' => [2, 4]]);
        $before = $run->sessions()->get()->toArray();
        $preview = $this->postJson('/program-runs/'.$run->id.'/change-preview')->assertOk()->json('preview');
        $entry = $run->sessions()->where('program_week', 2)->first();
        app(ActiveWorkout::class)->start($entry);
        $this->postJson('/program-runs/'.$run->id.'/changes', ['signature' => $preview['signature'], 'confirmed' => true])->assertUnprocessable();
        $this->assertSame(12, $run->sessions()->count());
        $preview = $this->postJson('/program-runs/'.$run->id.'/change-preview')->assertOk()->json('preview');
        $snapshot = $entry->fresh()->workout_snapshot;
        $this->postJson('/program-runs/'.$run->id.'/changes', ['signature' => $preview['signature'], 'confirmed' => true])->assertOk();
        $after = $run->sessions()->get()->toArray();
        $this->postJson('/program-runs/'.$run->id.'/changes', ['signature' => $preview['signature'], 'confirmed' => true])->assertUnprocessable();
        $this->assertSame($after, $run->sessions()->get()->toArray());
        $this->assertSame($snapshot, $entry->fresh()->workout_snapshot);
        $this->assertSame(count($after), $run->sessions()->distinct()->count('plan_occurrence_key'));
    }

    public function test_active_source_deletion_preserves_media_logging_history_and_run(): void
    {
        $plan = $this->program();
        $source = $plan->trainingDays()->first()->exercises->first();
        Storage::disk('public')->put('exercise-thumbnails/qa.jpg', 'image');
        $source->update(['thumbnail_path' => 'exercise-thumbnails/qa.jpg']);
        $run = $this->start($plan);
        $entry = $run->sessions()->first();
        $this->post('/schedule-entries/'.$entry->id.'/workout/start')->assertRedirect();
        $snapshot = $entry->fresh()->workout_snapshot;
        $movement = $entry->sessionExercises()->first();
        $this->delete('/training-plans/'.$plan->id)->assertRedirect();
        $this->assertNull($run->fresh()->training_plan_id);
        $this->assertSame($snapshot, $entry->fresh()->workout_snapshot);
        Storage::disk('public')->assertExists($snapshot['exercises'][0]['thumbnail_path']);
        foreach ($movement->sets as $set) {
            $this->patch('/session-sets/'.$set->id, ['actual_reps' => 10, 'actual_weight_kg' => 40, 'completed' => true])->assertSessionHasNoErrors();
        }
        $this->post('/schedule-entries/'.$entry->id.'/workout/finish', ['confirm_incomplete' => true])->assertSessionHasNoErrors();
        $this->get('/training-history/'.$entry->id)->assertOk();
        $this->get('/program-runs/'.$run->id)->assertInertia(fn (Assert $p) => $p->where('run.training_plan_id', null)->where('position.completed_workouts', 1));
        $this->postJson('/program-runs/'.$run->id.'/change-preview')->assertUnprocessable();
        $this->assertSame(12, $run->sessions()->count());
    }

    public function test_completion_reopening_recompletion_and_three_repeats_keep_independent_history(): void
    {
        $plan = $this->program();
        $run = $this->start($plan);
        $service = app(ProgramLifecycle::class);
        foreach (range(1, 4) as $week) {
            $this->resolve($run, $week);
        }
        $stamp = $run->fresh()->completed_at->toDateTimeString();
        $this->travelTo(now()->addDay());
        $service->sync($run->id);
        $this->assertSame($stamp, $run->fresh()->completed_at->toDateTimeString());
        $entry = $run->sessions()->first();
        $this->patch('/schedule-entries/'.$entry->id.'/status', ['status' => 'planned'])->assertSessionHasNoErrors();
        $this->get('/program-runs/'.$run->id)->assertInertia(fn (Assert $p) => $p->where('run.status', 'active')->where('run.completed_at', null)->where('position.current_week', 1)->where('summary', null));
        $this->patch('/schedule-entries/'.$entry->id.'/status', ['status' => 'completed'])->assertSessionHasNoErrors();
        $service->sync($run->id);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertNotSame($stamp, $run->fresh()->completed_at->toDateTimeString());
        $original = $run->fresh()->definition_snapshot;
        $sessions = $run->sessions()->get()->toArray();
        $plan->update(['name' => 'Revised repeat']);
        $plan->blocks()->first()->update(['name' => 'Revised foundation']);
        foreach (['2026-12-01', '2027-01-01', '2027-02-01'] as $date) {
            $repeat = $this->start($plan, $date);
            $this->assertNotSame($run->id, $repeat->id);
            $this->assertSame('Revised repeat', $repeat->definition_snapshot['name']);
            $this->assertSame(1, $service->position($repeat)['current_week']);
        }
        $this->assertSame($original, $run->fresh()->definition_snapshot);
        $this->assertSame($sessions, $run->sessions()->get()->toArray());
        $this->assertSame(4, $plan->programRuns()->count());
        $this->assertSame(48, $plan->user->scheduleEntries()->distinct()->count('plan_occurrence_key'));
        $this->get('/program-runs')->assertInertia(fn (Assert $p) => $p->has('runs.data', 4));
    }

    public function test_distinct_programs_and_same_definition_active_runs_do_not_share_week_or_completion(): void
    {
        $plan = $this->program();
        $first = $this->start($plan);
        $second = $this->start($plan, '2026-12-01');
        $other = $this->start($this->program($plan->user), '2026-12-10');
        foreach (range(1, 4) as $week) {
            $this->resolve($first, $week);
        }
        $service = app(ProgramLifecycle::class);
        $this->assertSame('completed', $first->fresh()->status);
        foreach ([$second, $other] as $run) {
            $this->assertSame('active', $run->fresh()->status);
            $this->assertSame(1, $service->position($run)['current_week']);
            $this->assertSame(0, $service->position($run)['completed_workouts']);
        }
        $this->get('/dashboard')->assertInertia(fn (Assert $p) => $p->has('programRuns', 2));
    }

    public function test_adherence_counts_due_skipped_and_missed_but_excludes_future_and_rest(): void
    {
        $plan = $this->program();
        $run = $this->start($plan);
        $service = app(ProgramLifecycle::class);
        $this->assertNull($service->position($run)['adherence']);
        $entries = $run->sessions()->get();
        foreach ($entries as $i => $entry) {
            $entry->update(['scheduled_date' => $i < 10 ? '2026-10-31' : '2026-12-01', 'status' => $i < 8 ? 'completed' : 'planned']);
        }
        $entries[8]->update(['status' => 'skipped']);
        $position = $service->position($run);
        $this->assertSame(10, $position['due']);
        $this->assertSame(8, $position['due_completed']);
        $this->assertSame(1, $position['due_skipped']);
        $this->assertSame(1, $position['missed']);
        $this->assertSame(80.0, $position['adherence']);
        $entries[9]->update(['status' => 'skipped']);
        $this->assertSame(80.0, $service->position($run)['adherence']);
        $entries[8]->update(['status' => 'completed']);
        $entries[9]->update(['status' => 'completed']);
        $this->assertSame(100.0, $service->position($run)['adherence']);
    }

    public function test_summary_uses_only_run_records_and_actual_historical_body_weight(): void
    {
        $plan = $this->program();
        $user = $plan->user;
        $run = $this->start($plan);
        foreach ([['2026-10-31', 85], ['2026-11-02', 84], ['2026-11-15', 83], ['2026-12-01', 82], ['2026-12-10', 70]] as [$date,$weight]) {
            $user->bodyWeightEntries()->create(['recorded_date' => $date, 'weight_kg' => $weight]);
        }
        $entries = $run->sessions()->get();
        $this->record($entries[0], weight: 40);
        $this->record($entries[1], weight: 45);
        $outside = $user->scheduleEntries()->create(['training_day_id' => $entries[0]->training_day_id, 'title' => 'Outside run', 'entry_type' => 'training', 'scheduled_date' => '2026-11-05', 'status' => 'planned']);
        $this->record($outside, weight: 50);
        $this->travelTo(now()->setDate(2026, 12, 1));
        foreach (range(1, 4) as $week) {
            $this->resolve($run, $week, 'skipped');
        }
        foreach ($entries->take(2) as $entry) {
            $entry->update(['status' => 'completed', 'completed_at' => now()]);
        }$run = app(ProgramLifecycle::class)->sync($run->id);
        $summary = app(ProgramLifecycle::class)->summary($run);
        $this->assertEquals(-2, $summary['body_weight_change']);
        $this->assertEquals(5, $summary['weight_gains'][0]['change_kg']);
        $this->assertNotContains($outside->id, array_column($summary['records'], 'session_id'));
        $events = app(ExerciseProgress::class)->records(app(ExerciseProgress::class)->workouts($user))['events'];
        $this->assertSame(count(array_filter($events, fn ($e) => in_array($e['session_id'], $entries->pluck('id')->all(), true))), $summary['pr_count']);
        $user->bodyWeightEntries()->delete();
        $this->assertNull(app(ProgramLifecycle::class)->summary($run)['body_weight_change']);
    }

    public function test_no_weight_or_actual_sets_never_fabricates_load_change_or_prs(): void
    {
        $run = $this->start($this->program());
        $entries = $run->sessions()->get();
        $this->record($entries[0], 20, 0);
        $this->record($entries[1], 20, null);
        foreach (range(1, 4) as $week) {
            $this->resolve($run, $week);
        }
        $summary = app(ProgramLifecycle::class)->summary($run->fresh());
        $this->assertSame(0, $summary['pr_count']);
        $this->assertSame([], $summary['weight_gains']);
        $this->assertNull($summary['body_weight_change']);
        $rows = app(ExerciseProgress::class)->workouts($run->user);
        $this->assertCount(2, $rows);
        $this->assertSame(20, $rows->first()['max_reps']);
        $this->assertNull($rows->first()['weighted_volume']);
    }

    public function test_coach_pause_resume_end_and_disconnect_preserve_owner_execution(): void
    {
        [$coach,$trainee,$plan,$run,$base,$relationship,$subscription] = $this->coaching();
        $definition = $run->definition_snapshot;
        $sessions = $run->sessions()->pluck('id')->all();
        foreach (['paused', 'ended'] as $status) {
            $subscription->update(['status' => $status]);
            $this->postJson($base.'/program-runs/'.$run->id.'/change-preview')->assertForbidden();
            $this->get($base.'/program-runs/'.$run->id)->assertOk();
            $this->actingAs($trainee);
            $entry = $run->sessions()->where('status', 'planned')->first();
            $this->record($entry);
            $this->get('/program-runs/'.$run->id)->assertOk();
            $subscription->update(['status' => 'active']);
            $this->actingAs($coach);
            $this->postJson($base.'/program-runs/'.$run->id.'/change-preview')->assertOk();
        }
        $relationship->update(['status' => 'ended']);
        $this->get($base.'/program-runs/'.$run->id)->assertNotFound();
        $this->postJson($base.'/program-runs/'.$run->id.'/change-preview')->assertNotFound();
        $this->actingAs($trainee)->get('/program-runs/'.$run->id)->assertOk();
        $this->assertSame($definition, $run->fresh()->definition_snapshot);
        $this->assertSame($sessions, $run->sessions()->pluck('id')->all());
    }

    public function test_coach_alternate_mutations_and_cross_owner_ids_are_denied(): void
    {
        [$coach,$trainee,$plan,$run,$base] = $this->coaching();
        $day = $plan->trainingDays()->first();
        $ex = $day->exercises->first();
        $entry = $run->sessions()->first();
        $this->put($base.'/training-days/'.$day->id, ['title' => 'Forbidden', 'type' => 'training', 'exercises' => [$ex->only('id', 'name', 'sets', 'reps')]])->assertForbidden();
        $this->post($base.'/training-days/'.$day->id.'/duplicate', ['confirmed' => true])->assertForbidden();
        $this->patch($base.'/training-days/'.$day->id.'/exercises/reorder', ['ids' => [$ex->id]])->assertForbidden();
        $this->patch($base.'/training-exercises/'.$ex->id.'/progression', ['token' => str_repeat('a', 64), 'value' => '50'])->assertForbidden();
        $this->post('/schedule-entries/'.$entry->id.'/workout/start')->assertForbidden();
        $this->patch('/schedule-entries/'.$entry->id.'/status', ['status' => 'completed'])->assertForbidden();
        $this->put('/schedule-entries/'.$entry->id, ['entry_type' => 'training', 'training_day_id' => $day->id, 'scheduled_date' => '2026-11-02', 'calories_burned' => 999])->assertForbidden();
        $weight = $trainee->bodyWeightEntries()->create(['recorded_date' => '2026-11-01', 'weight_kg' => 80]);
        $this->put('/body-weight/'.$weight->id, ['recorded_date' => '2026-11-01', 'weight_kg' => 70])->assertForbidden();
        $this->record($entry);
        $set = $entry->sessionExercises()->first()->sets->first();
        $this->patch('/session-sets/'.$set->id, ['actual_weight_kg' => 100, 'actual_reps' => 10, 'completed' => true])->assertForbidden();
        $stranger = User::factory()->create();
        $this->actingAs($stranger);
        foreach (['/program-runs/'.$run->id, '/training-plans/'.$plan->id, '/training-days/'.$day->id, '/schedule-entries/'.$entry->id] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->patch('/training-exercises/'.$ex->id.'/progression', ['token' => str_repeat('a', 64), 'value' => '50'])->assertForbidden();
        $this->postJson('/program-runs/'.$run->id.'/changes', ['confirmed' => true, 'signature' => str_repeat('a', 64)])->assertForbidden();
    }

    public function test_only_future_day_order_changes_remain_available_to_authorized_coach(): void
    {
        [$coach,$trainee,$plan,$run,$base] = $this->coaching();
        $ids = $plan->trainingDays()->pluck('id')->all();
        [$ids[2],$ids[3]] = [$ids[3], $ids[2]];
        $this->patch($base.'/training-plans/'.$plan->id.'/days/reorder', ['ids' => $ids])->assertSessionHasNoErrors();
        $this->assertSame($ids, $plan->trainingDays()->pluck('id')->all());
    }

    public function test_early_completed_program_history_is_readable_without_changing_simple_plan_or_date_filters(): void
    {
        $plan = $this->program();
        $run = $this->start($plan);
        $entry = $run->sessions()->where('program_week', 4)->first();
        $this->record($entry);
        $simple = $plan->user->scheduleEntries()->create(['title' => 'Future simple completion', 'entry_type' => 'training', 'scheduled_date' => '2027-01-01', 'status' => 'completed']);
        $this->get('/training-history')->assertInertia(fn (Assert $p) => $p->has('entries.data', 1)->where('entries.data.0.id', $entry->id));
        $this->get('/training-history/'.$entry->id)->assertOk();
        $this->get('/training-history/'.$simple->id)->assertNotFound();
        $this->get('/training-history?period=today')->assertInertia(fn (Assert $p) => $p->has('entries.data', 0));
        $this->assertSame(1, app(ProgramLifecycle::class)->position($run)['current_week']);
        $this->patch('/schedule-entries/'.$entry->id.'/status', ['status' => 'planned'])->assertSessionHasNoErrors();
        $this->get('/training-history')->assertInertia(fn (Assert $p) => $p->has('entries.data', 0));
        $this->get('/training-history/'.$entry->id)->assertNotFound();
    }

    public function test_progression_during_active_run_changes_only_selected_block_and_requires_fresh_review(): void
    {
        $plan = $this->program();
        $run = $this->start($plan);
        $service = app(ActiveWorkout::class);
        $evidence = $run->sessions()->where('program_week', 2)->get();
        foreach ($evidence->take(2) as $entry) {
            $this->record($entry);
        }
        $active = $run->sessions()->where('program_week', 1)->first();
        $service->start($active);
        $snapshot = $active->fresh()->workout_snapshot;
        $target = $plan->trainingDays()->where('position', 1)->first()->exercises->first();
        $other = $plan->trainingDays()->where('position', 2)->first()->exercises->first();
        $suggestion = app(ExerciseProgression::class)->forTemplate($plan->user, $target);
        $this->assertTrue($suggestion['can_apply']);
        $preview = $this->postJson('/program-runs/'.$run->id.'/change-preview')->assertOk()->json('preview');
        $this->patch('/training-exercises/'.$target->id.'/progression', ['token' => $suggestion['token'], 'value' => '42.5'])->assertSessionHasNoErrors();
        $this->assertEquals(42.5, $target->fresh()->target_weight);
        $this->assertEquals(40, $other->fresh()->target_weight);
        $this->patch('/training-exercises/'.$target->id.'/progression', ['token' => $suggestion['token'], 'value' => '45'])->assertSessionHasErrors('progression');
        $this->assertEquals(40, $evidence->last()->fresh()->program_prescription['exercises'][0]['target_weight']);
        $this->postJson('/program-runs/'.$run->id.'/changes', ['signature' => $preview['signature'], 'confirmed' => true])->assertUnprocessable();
        $preview = $this->postJson('/program-runs/'.$run->id.'/change-preview')->assertOk()->json('preview');
        $this->postJson('/program-runs/'.$run->id.'/changes', ['signature' => $preview['signature'], 'confirmed' => true])->assertOk();
        $this->assertEquals(42.5, $evidence->last()->fresh()->program_prescription['exercises'][0]['target_weight']);
        $this->assertEquals(40, $evidence->first()->fresh()->workout_snapshot['exercises'][0]['target_weight']);
        $this->assertSame($snapshot, $active->fresh()->workout_snapshot);
        $service->finish($active, true);
        $this->resolve($run, 1);
        $this->resolve($run, 2);
        $this->resolve($run, 3);
        $this->assertSame('Deload', app(ProgramLifecycle::class)->position($run)['current_block']['name']);
        $deload = $run->sessions()->where('program_week', 4)->first();
        $service->start($deload);
        $this->assertSame(1, $deload->sessionExercises()->first()->planned_sets);
        $this->assertEquals(25, $deload->sessionExercises()->first()->planned_weight_kg);
    }

    public function test_second_connected_coach_cannot_mutate_program_block_day_exercise_or_schedule(): void
    {
        [$first,$trainee,$plan,$run,$base] = $this->coaching();
        $second = User::factory()->create(['account_type' => 'coach']);
        $second->coachProfile()->create(['display_name' => 'Other coach']);
        $relation = CoachTraineeRelationship::create(['coach_user_id' => $second->id, 'trainee_user_id' => $trainee->id, 'status' => 'active', 'requested_by' => $second->id, 'requested_at' => now(), 'approved_at' => now()]);
        $relation->subscriptions()->create(['package_name' => 'Active service', 'starts_on' => today(), 'ends_on' => today()->addDays(90), 'status' => 'active']);
        $this->actingAs($second);
        $day = $plan->trainingDays()->where('position', 2)->first();
        $ex = $day->exercises->first();
        $entry = $run->sessions()->where('program_week', 3)->first();
        $this->get($base.'/program-runs/'.$run->id)->assertNotFound();
        $this->postJson($base.'/program-runs/'.$run->id.'/change-preview')->assertNotFound();
        $rows = $plan->blocks->map->only('id', 'name', 'type', 'start_week', 'end_week')->all();
        $this->put($base.'/training-plans/'.$plan->id.'/blocks', ['duration_weeks' => 4, 'blocks' => $rows])->assertNotFound();
        $this->delete($base.'/training-days/'.$day->id)->assertNotFound();
        $this->patch($base.'/training-exercises/'.$ex->id.'/progression', ['token' => str_repeat('a', 64), 'value' => '50'])->assertNotFound();
        $this->postJson($base.'/schedule-entries/'.$entry->id.'/reschedule-preview', ['scheduled_date' => '2026-12-01', 'scheduled_time' => '18:00', 'scope' => 'one'])->assertForbidden();
        $personal = $this->program($trainee);
        $this->get($base.'/training-plans/'.$personal->id)->assertForbidden();
        $this->assertSame(12, $run->sessions()->count());
        $this->assertEquals(40, $ex->fresh()->target_weight);
    }

    public function test_paginated_run_history_is_owned_and_independent_of_deleted_sources(): void
    {
        $plan = $this->program();
        foreach (range(1, 13) as $i) {
            $this->start($plan, '2027-01-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }
        $foreign = $this->start($this->program(), '2027-02-01');
        $this->actingAs($plan->user)->get('/program-runs')->assertInertia(fn (Assert $p) => $p->has('runs.data', 12)->where('runs.total', 13));
        $this->get('/program-runs?page=2')->assertInertia(fn (Assert $p) => $p->has('runs.data', 1)->where('runs.total', 13));
        $this->delete('/training-plans/'.$plan->id)->assertRedirect();
        $this->get('/program-runs?page=2')->assertInertia(fn (Assert $p) => $p->has('runs.data', 1)->where('runs.data.0.training_plan_id', null));
        $this->get('/program-runs/'.$foreign->id)->assertForbidden();
    }
}
