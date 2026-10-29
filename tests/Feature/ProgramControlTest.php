<?php

namespace Tests\Feature;

use App\Models\CoachTraineeRelationship;
use App\Models\ProgramRun;
use App\Models\ScheduleEntry;
use App\Models\TrainingPlan;
use App\Models\User;
use App\Services\ActiveWorkout;
use App\Services\ProgramLifecycle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProgramControlTest extends TestCase
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

    private function control(ProgramRun $run, array $data, string $base = ''): array
    {
        $preview = $this->postJson($base.'/program-runs/'.$run->id.'/control-preview', $data)->assertOk()->json('preview');
        $this->postJson($base.'/program-runs/'.$run->id.'/control', [...$data, 'signature' => $preview['signature'], 'confirmed' => true])->assertOk();

        return $preview;
    }

    public function test_known_pause_moves_only_unresolved_and_preserves_every_frozen_field(): void
    {
        $run = $this->start($this->program());
        $first = $run->sessions()->first();
        $this->record($first);
        $before = $run->sessions()->get()->keyBy('id');
        $snapshot = $run->definition_snapshot;
        $preview = $this->control($run, ['action' => 'pause', 'pause_date' => '2026-11-01', 'resume_date' => '2026-11-11']);
        $this->assertCount(11, $preview['move']);
        $this->assertSame('paused', app(ProgramLifecycle::class)->sync($run->id)->status);
        foreach ($run->sessions()->get() as $entry) {
            $original = $before[$entry->id];
            $this->assertEquals($original->only('program_week', 'program_context', 'program_prescription', 'plan_occurrence_key', 'training_day_id', 'scheduled_time', 'workout_snapshot', 'completed_at'), $entry->only('program_week', 'program_context', 'program_prescription', 'plan_occurrence_key', 'training_day_id', 'scheduled_time', 'workout_snapshot', 'completed_at'));
            $this->assertSame($original->scheduled_date->copy()->addDays($entry->id === $first->id ? 0 : 10)->toDateString(), $entry->scheduled_date->toDateString());
        }
        $this->assertSame($snapshot, $run->fresh()->definition_snapshot);
        $this->assertSame(1, app(ProgramLifecycle::class)->position($run->fresh())['current_week']);
        $this->travelTo(now()->addMonths(2));
        $this->assertSame('paused', app(ProgramLifecycle::class)->sync($run->id)->status);
        $this->assertSame(0, app(ProgramLifecycle::class)->position($run->fresh())['missed']);
    }

    public function test_indefinite_pause_no_fake_date_or_penalty_and_resume_anchors_remaining_sessions(): void
    {
        $run = $this->start($this->program());
        $before = $run->sessions()->pluck('scheduled_date', 'id')->map->toDateString()->all();
        $this->control($run, ['action' => 'pause', 'pause_date' => '2026-11-01']);
        $this->assertNull($run->fresh()->resume_on);
        $this->assertSame($before, $run->sessions()->pluck('scheduled_date', 'id')->map->toDateString()->all());
        $this->travelTo(now()->addWeeks(3));
        $position = app(ProgramLifecycle::class)->position($run->fresh());
        $this->assertSame(0, $position['due']);
        $this->assertNull($position['adherence']);
        $this->assertSame(1, $position['current_week']);
        $this->control($run, ['action' => 'resume', 'start_date' => '2026-11-25']);
        $this->assertSame('active', $run->fresh()->status);
        $this->assertSame('2026-11-25', $run->sessions()->first()->scheduled_date->toDateString());
        $this->assertSame(12, $run->sessions()->count());
        foreach (range(1, 4) as $week) {
            $this->resolve($run, $week);
        }
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_known_return_is_not_shifted_twice_and_partial_week_stays_in_place(): void
    {
        $run = $this->start($this->program());
        $this->record($run->sessions()->first());
        $this->control($run, ['action' => 'pause', 'pause_date' => '2026-11-01', 'resume_date' => '2026-11-11']);
        $dates = $run->sessions()->pluck('scheduled_date', 'id')->map->toDateString()->all();
        $preview = $this->control($run, ['action' => 'resume', 'start_date' => '2026-11-11']);
        $this->assertSame(0, $preview['days']);
        $this->assertSame($dates, $run->sessions()->pluck('scheduled_date', 'id')->map->toDateString()->all());
        $this->assertSame(1, app(ProgramLifecycle::class)->position($run->fresh())['current_week']);
    }

    public function test_shift_protects_manual_saved_completed_skipped_and_preserves_spacing(): void
    {
        $run = $this->start($this->program());
        $entries = $run->sessions()->get();
        $entries[0]->update(['schedule_managed' => false, 'scheduled_date' => '2027-01-01']);
        $this->record($entries[1]);
        $entries[2]->update(['status' => 'skipped']);
        $entries[3]->update(['workout_snapshot' => $entries[3]->program_prescription]);
        $protected = $entries->take(4)->map(fn ($e) => $e->fresh()->getAttributes())->all();
        $preview = $this->control($run, ['action' => 'shift', 'start_date' => '2026-12-01']);
        $this->assertCount(8, $preview['move']);
        $this->assertCount(2, $preview['protected']);
        $this->assertSame($protected, $entries->take(4)->map(fn ($e) => $e->fresh()->getAttributes())->all());
        $this->assertSame('2026-12-01', $entries[4]->fresh()->scheduled_date->toDateString());
        $rows = collect(app(ProgramLifecycle::class)->position($run->fresh())['sessions'])->keyBy('id');
        $this->assertFalse($rows[$entries[3]->id]['can_reschedule']);
        $this->assertTrue($rows[$entries[4]->id]['can_reschedule']);
        foreach ($preview['move'] as $row) {
            $this->assertSame($preview['days'], (int) Carbon::parse($row['scheduled_date'])->diffInDays($row['to']));
        }
    }

    public function test_definition_reconciliation_keeps_shifted_dates_and_resume_keeps_saved_prescriptions(): void
    {
        $plan = $this->program();
        $run = $this->start($plan);
        $this->control($run, ['action' => 'pause', 'pause_date' => '2026-11-01']);
        $original = $run->sessions()->first()->program_prescription;
        $plan->trainingDays()->first()->exercises()->update(['sets' => 5]);
        $this->control($run, ['action' => 'resume', 'start_date' => '2026-12-01']);
        $entry = $run->sessions()->first();
        $this->assertSame($original, $entry->program_prescription);
        $before = $run->sessions()->pluck('scheduled_date', 'id')->map->toDateString()->all();
        $review = $this->postJson('/program-runs/'.$run->id.'/change-preview')->assertOk()->json('preview');
        $this->postJson('/program-runs/'.$run->id.'/changes', ['signature' => $review['signature'], 'confirmed' => true])->assertOk();
        $this->assertSame($before, $run->sessions()->pluck('scheduled_date', 'id')->map->toDateString()->all());
        $this->assertSame(5, $entry->fresh()->program_prescription['exercises'][0]['sets']);
    }

    public function test_active_workout_blocks_pause_shift_and_end_without_data_loss(): void
    {
        $run = $this->start($this->program());
        $entry = app(ActiveWorkout::class)->start($run->sessions()->first());
        $before = $entry->fresh()->toArray();
        foreach (['pause', 'shift', 'end'] as $action) {
            $this->postJson('/program-runs/'.$run->id.'/control-preview', ['action' => $action, 'pause_date' => '2026-11-01', 'start_date' => '2026-12-01'])->assertUnprocessable()->assertJsonValidationErrors('program');
        }
        $this->assertSame($before, $entry->fresh()->toArray());
        $this->assertSame('active', $run->fresh()->status);
    }

    public function test_paused_and_ended_program_session_bypasses_are_rejected_but_history_remains(): void
    {
        $run = $this->start($this->program());
        $entry = $run->sessions()->first();
        $entry->update(['schedule_managed' => false]);
        $this->control($run, ['action' => 'pause', 'pause_date' => '2026-11-01']);
        foreach (['paused', 'ended'] as $status) {
            if ($status === 'ended') {
                $this->control($run, ['action' => 'end']);
            }
            $this->postJson('/schedule-entries/'.$entry->id.'/workout/start')->assertUnprocessable();
            $this->patchJson('/schedule-entries/'.$entry->id.'/status', ['status' => 'completed'])->assertUnprocessable();
            $this->postJson('/schedule-entries/'.$entry->id.'/reschedule-preview', ['scheduled_date' => '2026-12-10', 'scope' => 'one'])->assertForbidden();
            $this->get('/schedule-entries/'.$entry->id)->assertInertia(fn (Assert $p) => $p->where('programHeld', true)->where('canMove', false));
        }
    }

    public function test_end_keeps_history_manual_past_and_freezes_original_position_without_completion(): void
    {
        $run = $this->start($this->program());
        $this->resolve($run, 1);
        $rows = $run->sessions()->where('program_week', 2)->get();
        $rows[0]->update(['scheduled_date' => '2026-10-31']);
        $rows[1]->update(['schedule_managed' => false]);
        $before = $run->sessions()->where('status', 'completed')->get()->map->getAttributes()->all();
        $preview = $this->control($run, ['action' => 'end']);
        $this->assertCount(7, $preview['remove']);
        $this->assertCount(2, $preview['protected']);
        $this->assertSame('ended', app(ProgramLifecycle::class)->sync($run->id)->status);
        $this->assertNull($run->fresh()->completed_at);
        $this->assertNotNull($run->fresh()->ended_at);
        $this->assertSame($before, $run->sessions()->where('status', 'completed')->get()->map->getAttributes()->all());
        $this->travelTo(now()->addYear());
        $position = app(ProgramLifecycle::class)->position($run->fresh());
        $this->assertSame(2, $position['current_week']);
        $this->assertSame(12, $position['total_workouts']);
        $this->assertNull($position['next_workout']);
        $this->get('/program-runs/'.$run->id)->assertInertia(fn (Assert $p) => $p->where('summary', null)->where('run.status', 'ended'));
        $repeat = $this->start($run->trainingPlan, '2027-12-01');
        $this->assertNotSame($run->id, $repeat->id);
        $this->assertSame('ended', $run->fresh()->status);
    }

    public function test_completed_and_ended_runs_reject_every_control(): void
    {
        foreach (['completed', 'ended'] as $state) {
            $run = $this->start($this->program());
            if ($state === 'ended') {
                $this->control($run, ['action' => 'end']);
            } else {
                foreach (range(1, 4) as $week) {
                    $this->resolve($run, $week);
                }
            }
            foreach (['pause', 'resume', 'shift', 'end'] as $action) {
                $this->postJson('/program-runs/'.$run->id.'/control-preview', ['action' => $action, 'pause_date' => '2026-11-01', 'start_date' => '2026-12-01'])->assertUnprocessable();
            }
            $this->assertSame($state, $run->fresh()->status);
        }
    }

    public function test_all_control_previews_reject_stale_session_changes_and_replays(): void
    {
        foreach (['pause', 'resume', 'shift', 'end'] as $action) {
            $run = $this->start($this->program());
            if ($action === 'resume') {
                $this->control($run, ['action' => 'pause', 'pause_date' => '2026-11-01']);
            }
            $data = ['action' => $action, 'pause_date' => '2026-11-01', 'start_date' => '2026-12-01'];
            $base = '/program-runs/'.$run->id;
            $signature = $this->postJson($base.'/control-preview', $data)->assertOk()->json('preview.signature');
            $run->sessions()->first()->update(['notes' => 'Changed after review']);
            $this->postJson($base.'/control', [...$data, 'signature' => $signature, 'confirmed' => true])->assertUnprocessable();
            $preview = $this->control($run, $data);
            $dates = $run->sessions()->get()->toArray();
            $this->postJson($base.'/control', [...$data, 'signature' => $preview['signature'], 'confirmed' => true])->assertUnprocessable();
            $this->assertSame($dates, $run->sessions()->get()->toArray());
        }
    }

    public function test_invalid_dates_tampering_and_missing_confirmation_never_write(): void
    {
        $run = $this->start($this->program());
        $base = '/program-runs/'.$run->id;
        foreach ([['action' => 'pause', 'pause_date' => '2026-10-31'], ['action' => 'pause', 'pause_date' => '2026-11-01', 'resume_date' => '2026-11-01'], ['action' => 'shift', 'start_date' => '2026-10-31'], ['action' => 'resume', 'start_date' => 'banana'], ['action' => 'destroy']] as $data) {
            $this->postJson($base.'/control-preview', $data)->assertUnprocessable();
        }
        $data = ['action' => 'pause', 'pause_date' => '2026-11-01'];
        $preview = $this->postJson($base.'/control-preview', $data)->assertOk()->json('preview');
        $this->postJson($base.'/control', [...$data, 'signature' => $preview['signature']])->assertUnprocessable();
        $this->postJson($base.'/control', [...$data, 'signature' => str_repeat('a', 64), 'confirmed' => true])->assertUnprocessable();
        $this->assertSame('active', $run->fresh()->status);
        $this->assertSame(12, $run->sessions()->count());
    }

    public function test_missed_quick_reschedule_and_skip_use_existing_endpoints(): void
    {
        $run = $this->start($this->program());
        $this->travelTo(now()->addWeek());
        $entry = $run->sessions()->first();
        $data = ['scheduled_date' => '2026-12-01', 'scheduled_time' => '18:00', 'scope' => 'one'];
        $preview = $this->postJson('/schedule-entries/'.$entry->id.'/reschedule-preview', $data)->assertOk()->json('preview');
        $this->postJson('/schedule-entries/'.$entry->id.'/reschedule', [...$data, 'signature' => $preview['signature'], 'confirmed' => true])->assertOk();
        $this->assertFalse($entry->fresh()->schedule_managed);
        foreach ($run->sessions()->where('program_week', 1)->get() as $row) {
            $this->patch('/schedule-entries/'.$row->id.'/status', ['status' => 'skipped'])->assertSessionHasNoErrors();
        }
        $this->assertSame(2, app(ProgramLifecycle::class)->position($run->fresh())['current_week']);
    }

    public function test_multiple_runs_simple_sessions_and_dashboard_remain_isolated(): void
    {
        $plan = $this->program();
        $run = $this->start($plan);
        $second = $this->start($plan);
        $other = $this->start($this->program($plan->user));
        $simple = $plan->user->scheduleEntries()->create(['title' => 'Simple', 'entry_type' => 'training', 'status' => 'planned', 'scheduled_date' => '2026-11-02']);
        $before = $second->sessions()->get()->toArray();
        $this->control($run, ['action' => 'pause', 'pause_date' => '2026-11-01']);
        $this->get('/dashboard')->assertInertia(fn (Assert $p) => $p->has('programRuns', 3)->where('stats.upcoming', 7));
        $this->assertSame($before, $second->sessions()->get()->toArray());
        $this->assertSame('active', $other->fresh()->status);
        $this->assertSame('2026-11-02', $simple->fresh()->scheduled_date->toDateString());
        $this->get('/program-runs')->assertInertia(fn (Assert $p) => $p->has('runs.data', 3));
    }

    public function test_owner_can_control_orphan_run_without_definition_and_source_data_survives(): void
    {
        $run = $this->start($this->program());
        $snapshot = $run->definition_snapshot;
        $this->delete('/training-plans/'.$run->training_plan_id)->assertRedirect();
        $this->control($run, ['action' => 'pause', 'pause_date' => '2026-11-01']);
        $this->control($run, ['action' => 'resume', 'start_date' => '2026-12-01']);
        $this->control($run, ['action' => 'end']);
        $this->assertSame($snapshot, $run->fresh()->definition_snapshot);
        $this->assertNull($run->fresh()->training_plan_id);
    }

    public function test_full_coach_controls_subscription_expiry_and_disconnect_do_not_restrict_owner(): void
    {
        [$coach, $trainee, $plan, $run, $base, $relationship, $subscription] = $this->coaching();
        $this->control($run, ['action' => 'pause', 'pause_date' => '2026-11-01'], $base);
        $this->control($run, ['action' => 'resume', 'start_date' => '2026-12-01'], $base);
        $this->control($run, ['action' => 'shift', 'start_date' => '2026-12-10'], $base);
        $this->postJson($base.'/program-runs/'.$run->id.'/control-preview', ['action' => 'shift', 'start_date' => '2027-03-01'])->assertUnprocessable();
        $this->control($run, ['action' => 'pause', 'pause_date' => '2026-11-01'], $base);
        foreach (['paused', 'ended'] as $state) {
            $subscription->update(['status' => $state]);
            foreach (['pause', 'resume', 'shift', 'end'] as $action) {
                $this->postJson($base.'/program-runs/'.$run->id.'/control-preview', ['action' => $action, 'pause_date' => '2026-11-01', 'start_date' => '2026-12-10'])->assertForbidden();
            }
            $this->get($base.'/program-runs/'.$run->id)->assertOk();
        }
        $subscription->update(['status' => 'active']);
        $data = ['action' => 'resume', 'start_date' => '2026-12-10'];
        $sig = $this->postJson($base.'/program-runs/'.$run->id.'/control-preview', $data)->assertOk()->json('preview.signature');
        $subscription->update(['ends_on' => '2026-11-02']);
        $this->postJson($base.'/program-runs/'.$run->id.'/control', [...$data, 'signature' => $sig, 'confirmed' => true])->assertUnprocessable();
        $relationship->update(['status' => 'ended']);
        $this->postJson($base.'/program-runs/'.$run->id.'/control-preview', $data)->assertNotFound();
        $this->actingAs($trainee);
        $this->control($run, $data);
        $this->record($run->sessions()->first());
        $this->control($run, ['action' => 'end']);
        $this->assertSame('ended', $run->fresh()->status);
    }

    public function test_foreign_coach_personal_program_and_forged_owner_are_denied(): void
    {
        [$coach, $trainee, $plan, $run, $base] = $this->coaching();
        $personal = $this->start($this->program($trainee));
        $this->actingAs($coach);
        $this->postJson($base.'/program-runs/'.$personal->id.'/control-preview', ['action' => 'end'])->assertForbidden();
        $second = User::factory()->create(['account_type' => 'coach']);
        $second->coachProfile()->create(['display_name' => 'Other']);
        $relationship = CoachTraineeRelationship::create(['coach_user_id' => $second->id, 'trainee_user_id' => $trainee->id, 'status' => 'active', 'requested_by' => $second->id, 'requested_at' => now(), 'approved_at' => now()]);
        $relationship->subscriptions()->create(['package_name' => 'Other', 'starts_on' => today(), 'ends_on' => today()->addYear(), 'status' => 'active']);
        $this->actingAs($second)->postJson($base.'/program-runs/'.$run->id.'/control-preview', ['action' => 'end'])->assertNotFound();
        $this->actingAs(User::factory()->create())->postJson('/program-runs/'.$run->id.'/control-preview', ['action' => 'end', 'user_id' => $trainee->id])->assertForbidden();
        $this->actingAs($coach)->postJson('/schedule-entries/'.$run->sessions()->first()->id.'/workout/start')->assertForbidden();
        $this->assertSame('active', $run->fresh()->status);
    }

    public function test_pause_excludes_only_covered_due_workouts_and_handles_future_pause_boundary(): void
    {
        $run = $this->start($this->program());
        $this->control($run, ['action' => 'pause', 'pause_date' => '2026-11-05']);
        $this->travelTo(now()->setDate(2026, 11, 20));
        $position = app(ProgramLifecycle::class)->position($run->fresh());
        $this->assertSame(2, $position['missed']);
        $this->assertSame(2, $position['due']);
        $this->assertSame(1, $position['current_week']);
    }

    public function test_ended_history_status_corrections_preserve_saved_actuals_without_reopening_run(): void
    {
        $run = $this->start($this->program());
        $entry = $run->sessions()->first();
        $this->record($entry);
        $sets = $entry->sessionExercises()->with('sets')->get()->toArray();
        $this->control($run, ['action' => 'end']);
        foreach (['planned', 'skipped', 'completed'] as $status) {
            $this->patch('/schedule-entries/'.$entry->id.'/status', ['status' => $status])->assertSessionHasNoErrors();
            $this->assertSame('ended', app(ProgramLifecycle::class)->sync($run->id)->status);
        }
        $this->assertSame($sets, $entry->sessionExercises()->with('sets')->get()->toArray());
        $this->get('/training-history/'.$entry->id)->assertOk();
    }

    public function test_resolving_sessions_before_a_future_pause_can_still_complete_normally(): void
    {
        $run = $this->start($this->program());
        $this->control($run, ['action' => 'pause', 'pause_date' => '2027-01-01']);
        foreach ($run->sessions()->get() as $entry) {
            $this->patch('/schedule-entries/'.$entry->id.'/status', ['status' => 'completed'])->assertSessionHasNoErrors();
        }
        $this->assertSame('completed', app(ProgramLifecycle::class)->sync($run->id)->status);
    }

    public function test_run_position_queries_stay_constant_when_workout_count_grows(): void
    {
        $run = $this->start($this->program(weeks: 52));
        $this->control($run, ['action' => 'pause', 'pause_date' => '2026-11-01']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        app(ProgramLifecycle::class)->position($run->fresh());
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(2, $queries);
        $this->assertSame(156, $run->sessions()->count());
    }

    public function test_known_resume_rejects_elapsed_time_even_when_no_date_shift_was_needed(): void
    {
        $run = $this->start($this->program());
        $this->control($run, ['action' => 'pause', 'pause_date' => '2026-11-01', 'resume_date' => '2026-11-02']);
        $this->travelTo(now()->setDate(2026, 11, 3)->setTime(19, 0));
        $run->update(['resume_on' => '2026-11-03']);
        $this->postJson('/program-runs/'.$run->id.'/control-preview', ['action' => 'resume', 'start_date' => '2026-11-03'])->assertUnprocessable()->assertJsonValidationErrors('start_date');
        $this->assertSame('paused', $run->fresh()->status);
    }
}
