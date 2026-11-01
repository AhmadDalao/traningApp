<?php

namespace Tests\Feature;

use App\Models\CoachSubscriptionPlan;
use App\Models\CoachTraineeRelationship;
use App\Models\ProgramRun;
use App\Models\ScheduleEntry;
use App\Models\TrainingPlan;
use App\Models\User;
use App\Services\CoachingSubscriptions;
use App\Services\CoachSetup;
use App\Services\ExerciseLibrary;
use App\Services\ProgramLifecycle;
use App\Services\ProgramSchedule;
use App\Services\TrainingPlanDuplicator;
use App\Services\WorkoutDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AtomicCoachSetupTest extends TestCase
{
    use RefreshDatabase;

    private User $coach;

    private User $trainee;

    private CoachTraineeRelationship $relationship;

    private TrainingPlan $template;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 2)->setTime(10, 0));
        Storage::fake('public');
        $this->coach = User::factory()->create(['account_type' => 'coach']);
        $this->coach->coachProfile()->create(['display_name' => 'Coach']);
        $this->trainee = User::factory()->create();
        $this->relationship = CoachTraineeRelationship::create(['coach_user_id' => $this->coach->id, 'trainee_user_id' => $this->trainee->id, 'status' => 'active', 'requested_at' => now()]);
        $this->template = $this->coach->trainingPlans()->create(['name' => 'Strength', 'structure_type' => 'program', 'duration_weeks' => 2]);
        $this->template->forceFill(['workspace_type' => 'coach_template'])->save();
        $block = $this->template->blocks()->create(['name' => 'Build', 'start_week' => 1, 'end_week' => 2, 'position' => 0, 'type' => 'normal']);
        $day = $this->template->trainingDays()->create(['training_block_id' => $block->id, 'title' => 'Upper Body', 'type' => 'training', 'weekdays' => [1, 3, 5], 'default_time' => null, 'position' => 0]);
        $day->exercises()->create(['name' => 'Push-ups', 'sets' => 3, 'reps' => '10', 'position' => 0]);
        $this->base = '/coach/trainees/'.$this->trainee->id.'/setup';
        $this->actingAs($this->coach);
    }

    private function draft(array $changes = []): array
    {
        $old = $this->relationship->fresh()->setup_draft;
        $choices = $old['choices'] ?? [...app(CoachSetup::class)->defaults($this->relationship), 'training_plan_id' => $this->template->id, 'start_date' => today()->addWeek()->toDateString()];

        return $this->patchJson($this->base.'/draft', ['revision' => $old['revision'] ?? 0, 'begin' => isset($old['result']), 'choices' => [...$choices, ...$changes]])->assertOk()->json('draft');
    }

    private function review(array $changes = []): array
    {
        $draft = $this->draft($changes);
        $preview = $this->postJson($this->base.'/preview', ['revision' => $draft['revision'], 'token' => $draft['token']])->assertOk()->json('preview');

        return ['revision' => $draft['revision'], 'token' => $draft['token'], 'signature' => $preview['signature'], 'confirmed' => true, 'preview' => $preview];
    }

    private function activate(array $data): void
    {
        unset($data['preview']);
        $this->postJson($this->base.'/activate', $data)->assertOk();
    }

    public function test_draft_and_preview_create_no_domain_records_and_selection_is_restored(): void
    {
        $data = $this->review();
        $this->assertTrue($data['preview']['valid']);
        $this->assertDatabaseCount('training_plans', 1);
        foreach (['coach_subscriptions', 'coach_plan_assignments', 'program_runs', 'schedule_entries', 'notification_deliveries'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertCount(0, Storage::disk('public')->allFiles());
        $this->assertSame($this->template->id, $this->relationship->fresh()->setup_draft['choices']['training_plan_id']);
        $this->assertArrayNotHasKey('setup_draft', $this->relationship->fresh()->toArray());
        $this->post($this->base.'/coaching', [])->assertStatus(409);
        $this->get('/coach/trainees/'.$this->trainee->id.'/training-plans/create')->assertOk();
        $this->post('/coach/trainees/'.$this->trainee->id.'/training-plans', ['name' => 'Forbidden', 'structure_type' => 'simple'])->assertForbidden();
    }

    public function test_unchanged_draft_key_order_does_not_invalidate_review(): void
    {
        $data = $this->review();
        $choices = array_reverse($this->relationship->fresh()->setup_draft['choices'], true);
        $saved = $this->patchJson($this->base.'/draft', ['revision' => $data['revision'], 'choices' => $choices])->assertOk()->json('draft');
        $this->assertSame($data['revision'], $saved['revision']);
        $this->activate($data);
    }

    public function test_activation_starts_coaching_today_and_training_next_week_and_replays_result(): void
    {
        $data = $this->review();
        $this->activate($data);
        $this->activate($data);
        $this->assertDatabaseCount('coach_subscriptions', 1);
        $this->assertDatabaseCount('coach_plan_assignments', 1);
        $this->assertDatabaseCount('program_runs', 1);
        $this->assertDatabaseCount('schedule_entries', 6);
        $this->assertSame(today()->toDateString(), $this->relationship->subscriptions()->first()->starts_on->toDateString());
        $this->assertSame(today()->addWeek()->toDateString(), ProgramRun::first()->started_on->toDateString());
        $this->assertArrayHasKey('result', $this->relationship->fresh()->setup_draft);
    }

    public function test_short_package_extension_is_explicit_and_does_not_modify_package(): void
    {
        $package = CoachSubscriptionPlan::create(['coach_user_id' => $this->coach->id, 'name' => 'One week', 'duration_days' => 7, 'active' => true]);
        $data = $this->review(['package_id' => $package->id]);
        $this->assertFalse($data['preview']['valid']);
        $this->assertGreaterThan(0, $data['preview']['subscription']['extension_days']);
        $this->postJson($this->base.'/activate', $data)->assertUnprocessable();
        $this->activate($this->review(['include_extension' => true]));
        $this->assertSame(7, $package->fresh()->duration_days);
        $this->assertSame(today()->addDays(20)->toDateString(), $this->relationship->subscriptions()->first()->ends_on->toDateString());
    }

    public function test_existing_ongoing_and_longer_coverage_are_never_shortened(): void
    {
        $sub = $this->relationship->subscriptions()->create(['package_name' => 'Existing', 'status' => 'active', 'starts_on' => today()->subDays(20), 'ends_on' => today()->addYear()]);
        $this->activate($this->review());
        $this->assertTrue($sub->fresh()->ends_on->isSameDay(today()->addYear()));
        $this->assertTrue($sub->fresh()->starts_on->isSameDay(today()->subDays(20)));
    }

    public function test_paused_subscription_resumes_explicitly_and_reuses_existing_run(): void
    {
        $first = $this->review();
        $this->activate($first);
        $sub = $this->relationship->subscriptions()->first();
        $sub->update(['status' => 'paused']);
        $data = $this->review();
        $this->assertSame('resume', $data['preview']['subscription']['operation']);
        $this->assertTrue($data['preview']['already_scheduled']);
        $this->activate($data);
        $this->activate($data);
        $this->assertDatabaseCount('coach_subscriptions', 1);
        $this->assertDatabaseCount('program_runs', 1);
        $this->assertDatabaseCount('schedule_entries', 6);
        $this->assertSame('active', $sub->fresh()->status);
    }

    public function test_renewal_preserves_old_period_and_existing_assignment_schedule(): void
    {
        $this->activate($this->review());
        $old = $this->relationship->subscriptions()->first();
        $old->update(['status' => 'ended', 'ended_at' => now()]);
        $before = $old->fresh()->getAttributes();
        $data = $this->review();
        $this->assertSame('renew', $data['preview']['subscription']['operation']);
        $this->activate($data);
        $this->activate($data);
        $this->assertSame($before, $old->fresh()->getAttributes());
        $this->assertDatabaseCount('coach_subscriptions', 2);
        $this->assertDatabaseCount('coach_plan_assignments', 1);
        $this->assertDatabaseCount('program_runs', 1);
    }

    public function test_stale_draft_template_subscription_and_revoked_consent_reject_activation(): void
    {
        $data = $this->review();
        $this->draft(['package_name' => 'Changed']);
        $this->postJson($this->base.'/activate', $data)->assertStatus(409);
        $data = $this->review();
        $this->template->update(['name' => 'Edited']);
        $this->postJson($this->base.'/activate', $data)->assertUnprocessable();
        $data = $this->review();
        $this->relationship->subscriptions()->create(['package_name' => 'Other', 'starts_on' => today(), 'status' => 'active']);
        $this->postJson($this->base.'/activate', $data)->assertUnprocessable();
        $data = $this->review();
        $this->relationship->update(['status' => 'ended']);
        $this->postJson($this->base.'/activate', $data)->assertNotFound();
        $this->assertDatabaseCount('coach_plan_assignments', 0);
        $this->assertDatabaseCount('schedule_entries', 0);
    }

    public function test_deleted_template_is_an_actionable_preview_issue(): void
    {
        $draft = $this->draft();
        $this->template->delete();
        $this->postJson($this->base.'/preview', $draft)->assertOk()->assertJsonPath('preview.valid', false)->assertJsonPath('preview.issues.0.code', 'training_unavailable');
    }

    public function test_elapsed_row_is_not_omitted_and_anytime_correction_is_explicit(): void
    {
        $day = $this->template->trainingDays()->first();
        $day->update(['default_time' => '08:00']);
        $data = $this->review(['start_date' => today()->toDateString()]);
        $this->assertFalse($data['preview']['valid']);
        $this->assertCount(6, $data['preview']['sessions']);
        $this->assertNotEmpty($data['preview']['sessions'][0]['issues']);
        $fixed = $this->review(['time_overrides' => [$day->id.':'.today()->toDateString() => null]]);
        $this->postJson($this->base.'/activate', $data)->assertStatus(409);
        $this->activate($fixed);
        $this->assertNull(ScheduleEntry::orderBy('scheduled_date')->first()->scheduled_time);
        $this->assertSame('08:00', substr($day->fresh()->default_time, 0, 5));
    }

    public function test_time_passing_invalidates_preview_and_rolls_back_all_records(): void
    {
        $this->template->trainingDays()->update(['default_time' => '11:00']);
        $data = $this->review(['start_date' => today()->toDateString()]);
        $this->travel(2)->hours();
        $this->postJson($this->base.'/activate', $data)->assertUnprocessable();
        foreach (['coach_subscriptions', 'coach_plan_assignments', 'program_runs', 'schedule_entries'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_schedule_failure_rolls_back_subscription_copy_assignment_and_media(): void
    {
        Storage::disk('public')->put('exercise-thumbnails/original.jpg', 'original');
        $this->template->trainingDays()->first()->exercises()->update(['thumbnail_path' => 'exercise-thumbnails/original.jpg']);
        $data = $this->review();
        $this->partialMock(ProgramSchedule::class, fn ($mock) => $mock->shouldReceive('start')->once()->andThrow(new \RuntimeException('QA schedule failure')));
        $this->postJson($this->base.'/activate', $data)->assertStatus(500);
        foreach (['coach_subscriptions', 'coach_plan_assignments', 'program_runs', 'schedule_entries'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('training_plans', 1);
        $this->assertSame(['exercise-thumbnails/original.jpg'], Storage::disk('public')->allFiles());
        $this->assertArrayNotHasKey('result', $this->relationship->fresh()->setup_draft);
    }

    public function test_anytime_today_and_history_use_same_midnight_boundary(): void
    {
        $entry = $this->trainee->scheduleEntries()->create(['title' => 'Anytime', 'entry_type' => 'training', 'status' => 'planned', 'scheduled_date' => today()]);
        foreach (['10:00', '15:00', '23:59'] as $time) {
            $this->travelTo(now()->setTimeFromTimeString($time));
            $this->assertFalse(app(WorkoutDate::class)->elapsed($entry->scheduled_date->toDateString(), null));
            $this->assertSame(1, ScheduleEntry::upcoming()->count());
            $this->assertSame(0, ScheduleEntry::past()->count());
        }
        $this->travelTo(today()->addDay());
        $this->assertTrue(app(WorkoutDate::class)->elapsed($entry->scheduled_date->toDateString(), null));
        $this->assertSame(0, ScheduleEntry::upcoming()->count());
        $this->assertSame(1, ScheduleEntry::past()->count());
        foreach (['completed', 'skipped'] as $status) {
            $entry->update(['status' => $status, 'scheduled_date' => today()->addMonth()]);
            $this->assertSame(1, ScheduleEntry::historyVisible()->count());
        }
    }

    public function test_recorded_anytime_today_counts_in_personal_coach_and_program_progress(): void
    {
        $this->activate($this->review(['start_date' => today()->toDateString()]));
        $run = ProgramRun::first();
        $today = $run->sessions()->whereDate('scheduled_date', today())->first();
        $today->update(['status' => 'completed', 'completed_at' => now()]);
        $this->trainee->scheduleEntries()->create(['title' => 'Skipped', 'scheduled_date' => today(), 'scheduled_time' => null, 'entry_type' => 'training', 'status' => 'skipped']);
        $this->trainee->scheduleEntries()->create(['title' => 'Still available', 'scheduled_date' => today(), 'scheduled_time' => null, 'entry_type' => 'training', 'status' => 'planned']);
        $this->actingAs($this->trainee)->get('/progress')->assertInertia(fn (AssertableInertia $p) => $p->where('training.completed', 1)->where('training.skipped', 1)->where('training.due', 2)->where('training.completion_rate', 50));
        $this->actingAs($this->coach)->get('/coach/trainees/'.$this->trainee->id)->assertInertia(fn (AssertableInertia $p) => $p->where('completion', 50));
        $position = app(ProgramLifecycle::class)->position($run->fresh());
        $this->assertSame(1, $position['due_completed']);
        $this->assertEquals(100, $position['adherence']);
        $this->assertSame(0, $position['missed']);
    }

    public function test_timed_predicates_match_at_the_exact_application_local_timestamp(): void
    {
        $date = today()->toDateString();
        foreach ([null, '09:59:59', '10:00:00', '10:00:01'] as $time) {
            $entry = $this->trainee->scheduleEntries()->create(['title' => 'Boundary', 'entry_type' => 'training', 'status' => 'planned', 'scheduled_date' => $date, 'scheduled_time' => $time]);
            $past = app(WorkoutDate::class)->elapsed($date, $time);
            $this->assertSame($time !== null && $time <= '10:00:00', $past);
            $this->assertSame($past, ScheduleEntry::whereKey($entry->id)->past()->exists());
            $this->assertSame(! $past, ScheduleEntry::whereKey($entry->id)->upcoming()->exists());
        }
    }

    public function test_simple_fixed_and_ongoing_plans_preserve_modes_and_review_all_coverage(): void
    {
        $this->template->update(['structure_type' => 'simple', 'duration_weeks' => null]);
        $data = $this->review(['schedule_mode' => 'ongoing', 'coaching_ends_on' => today()->addDays(14)->toDateString()]);
        $this->assertFalse($data['preview']['valid']);
        $this->assertGreaterThan(6, $data['preview']['count']);
        $this->activate($this->review(['include_extension' => true]));
        $copy = $this->trainee->trainingPlans()->first();
        $this->assertSame('ongoing', $copy->schedule_mode);
        $this->assertTrue($copy->schedule_enabled);
        $this->assertNull($copy->end_date);
        $this->assertDatabaseCount('program_runs', 0);
    }

    public function test_fixed_simple_plan_uses_reviewed_dates_and_overrides(): void
    {
        $this->template->update(['structure_type' => 'simple', 'duration_weeks' => null]);
        $day = $this->template->trainingDays()->first();
        $data = $this->review(['start_date' => today()->toDateString(), 'end_date' => today()->addDays(6)->toDateString(),
            'day_options' => [$day->id => ['weekdays' => [1, 2], 'time' => '18:00']], 'time_overrides' => [$day->id.':'.today()->toDateString() => null]]);
        $this->activate($data);
        $copy = $this->trainee->trainingPlans()->first();
        $this->assertSame('fixed', $copy->schedule_mode);
        $this->assertFalse($copy->schedule_enabled);
        $this->assertSame([1, 2], $copy->trainingDays()->first()->weekdays);
        $this->assertSame([1, 3, 5], $day->fresh()->weekdays);
        $this->assertSame(2, ScheduleEntry::count());
    }

    public function test_cancelled_simple_schedule_is_not_restarted_during_renewal(): void
    {
        $this->template->update(['structure_type' => 'simple', 'duration_weeks' => null]);
        $this->activate($this->review(['end_date' => today()->addDays(20)->toDateString()]));
        $plan = $this->trainee->trainingPlans()->first();
        $this->assertNotNull($plan->schedule_actor_id);
        foreach (ScheduleEntry::all() as $entry) {
            DB::table('schedule_occurrence_cancellations')->insert(['training_plan_id' => $plan->id, 'occurrence_key' => $entry->plan_occurrence_key, 'created_at' => now()]);
            $entry->delete();
        }
        $this->relationship->subscriptions()->update(['status' => 'ended']);
        $preview = $this->review();
        $this->assertTrue($preview['preview']['already_scheduled']);
        $this->assertSame(0, $preview['preview']['count']);
        $this->activate($preview);
        $this->assertDatabaseCount('schedule_entries', 0);
        $this->assertDatabaseCount('coach_plan_assignments', 1);
        $this->assertDatabaseCount('coach_subscriptions', 2);
    }

    public function test_existing_ongoing_and_ongoing_package_stay_ongoing(): void
    {
        $sub = $this->relationship->subscriptions()->create(['package_name' => 'Ongoing', 'starts_on' => today()->subWeek(), 'status' => 'active']);
        $this->activate($this->review(['coaching_ends_on' => today()->addMonth()->toDateString()]));
        $this->assertNull($sub->fresh()->ends_on);
        $this->assertDatabaseCount('coach_subscriptions', 1);
    }

    public function test_expired_active_row_is_preserved_and_renewal_creates_new_period(): void
    {
        $old = $this->relationship->subscriptions()->create(['package_name' => 'Expired', 'starts_on' => today()->subMonth(), 'ends_on' => today()->subDay(), 'status' => 'active']);
        $before = $old->fresh()->getAttributes();
        $data = $this->review();
        $this->assertSame('renew', $data['preview']['subscription']['operation']);
        $this->activate($data);
        $this->assertSame($before, $old->fresh()->getAttributes());
        $this->assertDatabaseCount('coach_subscriptions', 2);
    }

    public function test_foreign_template_package_and_assigned_plan_cannot_be_saved(): void
    {
        $other = User::factory()->create(['account_type' => 'coach']);
        $foreign = $other->trainingPlans()->create(['name' => 'Private']);
        $foreign->forceFill(['workspace_type' => 'coach_template'])->save();
        $choices = [...app(CoachSetup::class)->defaults($this->relationship), 'training_plan_id' => $foreign->id];
        $this->patchJson($this->base.'/draft', ['revision' => 0, 'choices' => $choices])->assertNotFound();
        $package = CoachSubscriptionPlan::create(['coach_user_id' => $other->id, 'name' => 'Private', 'active' => true]);
        $choices['training_plan_id'] = $this->template->id;
        $choices['package_id'] = $package->id;
        $this->patchJson($this->base.'/draft', ['revision' => 0, 'choices' => $choices])->assertNotFound();
        $choices['package_id'] = null;
        $choices['selection_type'] = 'assigned';
        $this->patchJson($this->base.'/draft', ['revision' => 0, 'choices' => $choices])->assertNotFound();
        $this->actingAs($this->trainee)->get($this->base)->assertForbidden();
        $other->coachProfile()->create(['display_name' => 'Other']);
        $this->actingAs($other)->get($this->base)->assertNotFound();
        $this->assertNull($this->relationship->fresh()->setup_draft);
    }

    public function test_client_result_and_ownership_fields_cannot_activate_anything(): void
    {
        $choices = [...app(CoachSetup::class)->defaults($this->relationship), 'training_plan_id' => $this->template->id, 'assignment_id' => 123];
        $this->patchJson($this->base.'/draft', ['revision' => 0, 'choices' => $choices, 'result' => ['subscription_id' => 123]])->assertUnprocessable();
        $this->assertNull($this->relationship->fresh()->setup_draft);
    }

    public function test_midnight_invalidates_activation_start_date_but_does_not_modify_draft(): void
    {
        $data = $this->review();
        $before = $this->relationship->fresh()->setup_draft;
        $this->travelTo(today()->addDay());
        $this->postJson($this->base.'/activate', $data)->assertUnprocessable();
        $this->assertSame($before, $this->relationship->fresh()->setup_draft);
        $this->assertDatabaseCount('coach_subscriptions', 0);
    }

    public function test_program_pause_is_distinct_from_subscription_pause_cta(): void
    {
        $this->activate($this->review());
        ProgramRun::first()->update(['status' => 'paused', 'paused_from' => today()]);
        $this->get('/coach/trainees/'.$this->trainee->id)->assertInertia(fn (AssertableInertia $p) => $p->where('state.subscription_action', null));
        $this->relationship->subscriptions()->update(['status' => 'paused']);
        $this->get('/coach/trainees/'.$this->trainee->id)->assertInertia(fn (AssertableInertia $p) => $p->where('state.subscription_action.label', 'Resume Coaching'));
    }

    public function test_subscription_failure_leaves_only_the_saved_draft(): void
    {
        $data = $this->review();
        $this->partialMock(CoachingSubscriptions::class, fn ($m) => $m->shouldReceive('applySetup')->andThrow(new \RuntimeException('QA subscription failure')));
        $this->postJson($this->base.'/activate', $data)->assertStatus(500);
        foreach (['coach_subscriptions', 'coach_plan_assignments', 'program_runs', 'schedule_entries'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('training_plans', 1);
    }

    public function test_media_copy_failure_rolls_back_earlier_copy_and_subscription(): void
    {
        Storage::disk('public')->put('exercise-thumbnails/a.jpg', 'a');
        $exercise = $this->template->trainingDays()->first()->exercises()->first();
        $exercise->update(['thumbnail_path' => 'exercise-thumbnails/a.jpg', 'custom_icon_path' => 'exercise-thumbnails/a.jpg']);
        $data = $this->review();
        $library = app(ExerciseLibrary::class);
        $calls = 0;
        $this->partialMock(ExerciseLibrary::class, function ($m) use ($library, &$calls) {
            $m->shouldReceive('copyMedia')->andReturnUsing(function ($source, $field, $dir, &$paths) use ($library, &$calls) {
                if (++$calls === 2) {
                    throw new \RuntimeException('QA copy failure');
                }

                return $library->copyMedia($source, $field, $dir, $paths);
            });
        });
        $this->postJson($this->base.'/activate', $data)->assertStatus(500);
        foreach (['coach_subscriptions', 'coach_plan_assignments', 'program_runs', 'schedule_entries'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('training_plans', 1);
        $this->assertSame(['exercise-thumbnails/a.jpg'], Storage::disk('public')->allFiles());
    }

    public function test_deleted_package_and_multiple_invalid_workouts_are_identified(): void
    {
        $package = CoachSubscriptionPlan::create(['coach_user_id' => $this->coach->id, 'name' => 'Short', 'active' => true, 'duration_days' => 1]);
        $draft = $this->draft(['package_id' => $package->id]);
        $package->delete();
        $preview = $this->postJson($this->base.'/preview', $draft)->assertOk()->json('preview');
        $this->assertContains('package_unavailable', array_column($preview['issues'], 'code'));
        $data = $this->review(['package_id' => null, 'coaching_ends_on' => today()->toDateString()]);
        $invalid = array_filter($data['preview']['issues'], fn ($i) => $i['code'] === 'schedule_row');
        $this->assertCount(6, $invalid);
        foreach ($invalid as $issue) {
            $this->assertSame('Upper Body', $issue['title']);
            $this->assertNotEmpty($issue['date']);
        }
        $this->postJson($this->base.'/activate', $data)->assertUnprocessable();
        $this->assertDatabaseCount('schedule_entries', 0);
    }

    public function test_new_ongoing_package_has_no_artificial_end_date(): void
    {
        $package = CoachSubscriptionPlan::create(['coach_user_id' => $this->coach->id, 'name' => 'Ongoing', 'active' => true]);
        $this->activate($this->review(['package_id' => $package->id]));
        $this->assertNull($this->relationship->subscriptions()->first()->ends_on);
    }

    public function test_unrelated_latest_assignment_is_not_reused_for_selected_template(): void
    {
        $other = $this->coach->trainingPlans()->create(['name' => 'Other']);
        $other->forceFill(['workspace_type' => 'coach_template'])->save();
        $this->relationship->subscriptions()->create(['package_name' => 'Ongoing', 'starts_on' => today(), 'status' => 'active']);
        app(TrainingPlanDuplicator::class)->assign($this->coach, $this->trainee, $other, ['start_date' => today(), 'end_date' => today()->addWeek()]);
        $data = $this->review(['selection_type' => 'template', 'training_plan_id' => $this->template->id]);
        $this->assertFalse($data['preview']['assignment_reused']);
        $this->activate($data);
        $this->assertDatabaseCount('coach_plan_assignments', 2);
        $this->assertSame('Strength', ProgramRun::first()->trainingPlan->name);
    }
}
