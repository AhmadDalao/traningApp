<?php

namespace Tests\Feature;

use App\Models\CoachTraineeRelationship;
use App\Models\ProgramRun;
use App\Models\ScheduleEntry;
use App\Models\TrainingPlan;
use App\Models\User;
use App\Services\CoachWorkspaceSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CoachWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $coach;

    private User $trainee;

    private CoachTraineeRelationship $relationship;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 2)->setTime(10, 0));
        Storage::fake('public');
        $this->coach = User::factory()->create(['account_type' => 'coach']);
        $this->coach->coachProfile()->create(['display_name' => 'Coach']);
        $this->trainee = User::factory()->create();
        $this->relationship = $this->connect($this->trainee);
        $this->base = '/coach/trainees/'.$this->trainee->id;
        $this->actingAs($this->coach);
    }

    private function connect(User $trainee): CoachTraineeRelationship
    {
        return CoachTraineeRelationship::create(['coach_user_id' => $this->coach->id, 'trainee_user_id' => $trainee->id, 'status' => 'active', 'requested_at' => now(), 'approved_at' => now()]);
    }

    private function coaching(): array
    {
        return ['package_name' => 'Weekly coaching', 'starts_on' => today()->toDateString(), 'ends_on' => today()->addDays(90)->toDateString(), 'open_ended' => false];
    }

    private function template(bool $program = true): TrainingPlan
    {
        $plan = $this->coach->trainingPlans()->create(['name' => 'Strength', 'structure_type' => $program ? 'program' : 'simple', 'duration_weeks' => $program ? 2 : null]);
        $plan->forceFill(['workspace_type' => 'coach_template'])->save();
        $block = $program ? $plan->blocks()->create(['name' => 'Foundation', 'start_week' => 1, 'end_week' => 2, 'position' => 0, 'type' => 'normal']) : null;
        $day = $plan->trainingDays()->create(['training_block_id' => $block?->id, 'title' => 'Upper body', 'type' => 'training', 'weekdays' => [1, 3, 5], 'default_time' => '18:00', 'position' => 0]);
        $day->exercises()->create(['name' => 'Push-ups', 'sets' => 3, 'reps' => '10', 'position' => 0]);

        return $plan;
    }

    private function assign(bool $program = true): TrainingPlan
    {
        $this->post($this->base.'/setup/coaching', $this->coaching())->assertRedirect()->assertSessionHasNoErrors();
        $source = $this->template($program);
        $this->post($this->base.'/setup/training', ['training_plan_id' => $source->id, 'confirmed' => true, 'schedule_mode' => 'fixed', 'start_date' => today()->addDay()->toDateString(), 'end_date' => today()->addDays(14)->toDateString()])->assertRedirect()->assertSessionHasNoErrors();

        return $this->relationship->assignments()->firstOrFail()->plan;
    }

    private function preview(TrainingPlan $plan): array
    {
        $data = ['training_plan_id' => $plan->id, 'start_date' => today()->addDay()->toDateString(), 'nonce' => (string) Str::uuid()];
        $preview = $this->postJson($this->base.'/setup/preview', $data)->assertOk()->json('preview');

        return [...$data, 'signature' => $preview['signature'], 'confirmed' => true];
    }

    private function state(string $state): void
    {
        $this->get($this->base.'/setup')->assertInertia(fn (Assert $p) => $p->where('state.display_state', $state));
    }

    public function test_new_connection_has_checklist_and_partial_setup_resumes_without_duplicate_coaching(): void
    {
        $this->get('/coach/trainees')->assertInertia(fn (Assert $p) => $p->where('relationships.per_page', 25)->where('relationships.data.0.display_state', 'setup')->where('relationships.data.0.action.url', $this->base.'/setup'));
        $this->state('setup');
        for ($i = 0; $i < 2; $i++) {
            $this->post($this->base.'/setup/coaching', $this->coaching())->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('coach_subscriptions', 1);
        $this->post($this->base.'/setup/finish')->assertRedirect($this->base);
        $this->get($this->base.'/setup')->assertInertia(fn (Assert $p) => $p->where('state.subscription.effective_status', 'active')->where('plan', null));
    }

    public function test_program_assignment_resume_preserves_independent_copy_and_activation_is_idempotent(): void
    {
        $copy = $this->assign();
        $assignment = $copy->assignment;
        $this->post($this->base.'/setup/training', ['training_plan_id' => $assignment->source_training_plan_id, 'confirmed' => true, 'schedule_mode' => 'fixed'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('coach_plan_assignments', 1);
        $this->assertSame($this->trainee->id, $copy->user_id);
        $assignment->source->update(['name' => 'Changed template']);
        $this->assertSame('Strength', $copy->fresh()->name);
        $this->state('setup');
        $data = $this->preview($copy);
        $this->postJson($this->base.'/setup/activate', $data)->assertOk()->assertJsonPath('url', $this->base);
        $count = ScheduleEntry::count();
        $this->assertGreaterThan(0, $count);
        $this->postJson($this->base.'/setup/activate', $data)->assertOk();
        $this->postJson($this->base.'/setup/activate', [...$data, 'nonce' => (string) Str::uuid()])->assertOk();
        $this->assertDatabaseCount('program_runs', 1);
        $this->assertDatabaseCount('schedule_entries', $count);
        $this->state('active');
        $this->assertSame(0, ScheduleEntry::where('user_id', '!=', $this->trainee->id)->count());
    }

    public function test_simple_plan_preview_and_activation_reuse_calendar_and_never_duplicate_sessions(): void
    {
        $copy = $this->assign(false);
        $data = $this->preview($copy);
        $this->postJson($this->base.'/setup/activate', $data)->assertOk();
        $count = ScheduleEntry::count();
        $this->postJson($this->base.'/setup/activate', $data)->assertOk();
        $this->assertDatabaseCount('schedule_entries', $count);
        $this->assertDatabaseCount('program_runs', 0);
        $this->state('active');
    }

    public function test_stale_schedule_and_validation_failures_preserve_successful_steps(): void
    {
        $copy = $this->assign();
        $data = $this->preview($copy);
        $copy->trainingDays()->first()->update(['default_time' => '19:00']);
        $this->postJson($this->base.'/setup/activate', $data)->assertUnprocessable();
        $this->assertDatabaseCount('program_runs', 0);
        $this->assertDatabaseCount('schedule_entries', 0);
        $this->assertDatabaseCount('coach_plan_assignments', 1);
        $this->assertDatabaseCount('coach_subscriptions', 1);
        $this->post($this->base.'/setup/coaching', ['starts_on' => 'not-a-date'])->assertSessionHasErrors();
        $this->assertDatabaseCount('coach_subscriptions', 1);
    }

    public function test_pending_ended_foreign_and_personal_ids_are_rejected(): void
    {
        $copy = $this->assign();
        $personal = $this->trainee->trainingPlans()->create(['name' => 'Personal']);
        $this->postJson($this->base.'/setup/preview', ['training_plan_id' => $personal->id])->assertNotFound();
        $other = User::factory()->create(['account_type' => 'coach']);
        $other->coachProfile()->create(['display_name' => 'Coach B']);
        $foreign = $other->trainingPlans()->create(['name' => 'Foreign']);
        $foreign->forceFill(['workspace_type' => 'coach_template'])->save();
        $this->post($this->base.'/setup/training', ['training_plan_id' => $foreign->id, 'confirmed' => true])->assertNotFound();
        $this->actingAs($other)->get($this->base.'/setup')->assertNotFound();
        $this->postJson($this->base.'/setup/activate', ['training_plan_id' => $copy->id])->assertNotFound();
        $this->actingAs($this->coach);
        foreach (['pending', 'ended'] as $status) {
            $this->relationship->update(['status' => $status]);
            $this->get($this->base.'/setup')->assertNotFound();
            $this->post($this->base.'/setup/coaching', $this->coaching())->assertNotFound();
        }
    }

    public function test_old_relationship_id_cannot_target_a_new_consent_cycle(): void
    {
        $this->relationship->update(['status' => 'ended']);
        $current = $this->connect($this->trainee);
        $this->post('/coach/subscriptions', ['relationship_id' => $this->relationship->id, ...$this->coaching()])->assertForbidden();
        $this->assertSame(0, $current->subscriptions()->count());
        $this->post('/coach/subscriptions', ['relationship_id' => $current->id, ...$this->coaching()])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, $current->subscriptions()->count());
    }

    public function test_revoked_subscription_blocks_assignment_preview_and_activation(): void
    {
        $copy = $this->assign();
        $data = $this->preview($copy);
        $this->relationship->subscriptions()->update(['status' => 'paused']);
        $this->state('paused');
        $this->postJson($this->base.'/setup/preview', $data)->assertForbidden();
        $this->postJson($this->base.'/setup/activate', $data)->assertForbidden();
        $this->post($this->base.'/setup/training', ['training_plan_id' => $copy->assignment->source_training_plan_id, 'confirmed' => true, 'schedule_mode' => 'fixed'])->assertForbidden();
        $this->get($this->base.'/coaching')->assertOk();
    }

    public function test_paused_program_is_not_incomplete_and_coaching_pause_is_distinct(): void
    {
        $copy = $this->assign();
        $this->postJson($this->base.'/setup/activate', $this->preview($copy))->assertOk();
        ProgramRun::first()->update(['status' => 'paused', 'paused_from' => today()]);
        $this->get($this->base.'/setup')->assertInertia(fn (Assert $p) => $p->where('state.display_state', 'paused')->where('state.reason', 'Program paused'));
        $this->relationship->subscriptions()->update(['status' => 'paused']);
        $this->get($this->base.'/setup')->assertInertia(fn (Assert $p) => $p->where('state.reason', 'Coaching paused'));
    }

    public function test_missed_workouts_exclude_held_active_and_other_coach_sessions(): void
    {
        $copy = $this->assign();
        $this->postJson($this->base.'/setup/activate', $this->preview($copy))->assertOk();
        $entry = ScheduleEntry::first();
        $entry->update(['scheduled_date' => today()->subDay()]);
        $this->state('attention');
        $entry->update(['started_at' => now()]);
        $this->state('active');
        $entry->update(['started_at' => null, 'scheduled_by_user_id' => $this->trainee->id]);
        $this->state('active');
        $this->actingAs($this->coach)->get($this->base.'/schedule-entries/'.$entry->id.'/edit')->assertForbidden();
    }

    public function test_missed_today_uses_calendar_dates_on_both_databases(): void
    {
        $copy = $this->assign(false);
        $this->postJson($this->base.'/setup/activate', $this->preview($copy))->assertOk();
        $entry = ScheduleEntry::first();
        $entry->update(['scheduled_date' => today(), 'scheduled_time' => '09:00']);
        $this->state('attention');
        $entry->update(['scheduled_time' => '18:00']);
        $this->state('active');
        $entry->update(['status' => 'skipped']);
        $this->get('/coach')->assertInertia(fn (Assert $p) => $p->where('stats.today', 0));
    }

    public function test_empty_simple_schedule_cannot_claim_activation(): void
    {
        $copy = $this->assign(false);
        $copy->trainingDays()->first()->update(['weekdays' => []]);
        $data = $this->preview($copy);
        $this->postJson($this->base.'/setup/activate', $data)->assertUnprocessable();
        $this->assertDatabaseCount('schedule_entries', 0);
        $this->state('setup');
    }

    public function test_ending_window_is_centralized_and_expired_coaching_needs_review(): void
    {
        $copy = $this->assign();
        $this->postJson($this->base.'/setup/activate', $this->preview($copy))->assertOk();
        $this->relationship->subscriptions()->update(['ends_on' => today()->addDays(CoachWorkspaceSummary::ENDING_SOON_DAYS)]);
        $this->state('attention');
        $this->relationship->subscriptions()->update(['ends_on' => today()->subDay()]);
        $this->state('setup');
    }

    public function test_filters_search_pagination_and_bounded_queries_use_only_authorized_summary_data(): void
    {
        foreach (range(1, 26) as $i) {
            $this->connect(User::factory()->create(['name' => 'Trainee '.$i]));
        }
        $foreign = User::factory()->create(['name' => 'Private stranger']);
        $this->get('/coach/trainees?status=setup')->assertInertia(fn (Assert $p) => $p->where('relationships.total', 27)->has('relationships.data', 25)->where('counts.setup', 27));
        $this->get('/coach/trainees?search='.$this->trainee->training_code)->assertInertia(fn (Assert $p) => $p->where('relationships.total', 1)->where('relationships.data.0.trainee.id', $this->trainee->id));
        $this->get('/coach/trainees?search='.$foreign->training_code)->assertInertia(fn (Assert $p) => $p->where('relationships.total', 0));
        foreach (['active', 'attention', 'paused', 'pending'] as $status) {
            $this->get('/coach/trainees?status='.$status)->assertInertia(fn (Assert $p) => $p->where('relationships.total', 0));
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/coach/trainees')->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query');
        $this->assertLessThan(18, $queries->count());
        $this->assertFalse($queries->contains(fn ($sql) => str_contains($sql, 'session_sets')));
        DB::disableQueryLog();
    }

    public function test_context_persists_and_personal_workspace_has_no_trainee_context(): void
    {
        $this->assign();
        foreach (['', '/training-plans', '/calendar', '/training-history', '/progress', '/coaching'] as $path) {
            $this->get($this->base.$path)->assertInertia(fn (Assert $p) => $p->where('trainingContext.trainee.id', $this->trainee->id)->where('trainingContext.summary.plan.name', 'Strength'));
        }
        $this->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('trainingContext.coaching', false)->where('trainingContext.trainee', null));
    }

    public function test_assign_from_template_and_package_creation_keep_the_same_setup_context(): void
    {
        $template = $this->template();
        $this->get('/coach/training-plans/'.$template->id.'/assign')->assertInertia(fn (Assert $p) => $p->has('relationships.data', 1)->where('relationships.data.0.has_training', false));
        $this->get($this->base.'/assign?template='.$template->id)->assertInertia(fn (Assert $p) => $p->where('selectedTemplate', $template->id));
        $this->get($this->base.'/setup?template='.$template->id)->assertInertia(fn (Assert $p) => $p->where('selectedTemplate', $template->id));
        $this->from($this->base.'/setup')->post('/coach/subscription-plans', ['name' => '12 weeks', 'duration_days' => 84, 'active' => true])->assertRedirect($this->base.'/setup');
    }

    public function test_overview_upcoming_excludes_completed_skipped_started_and_held_workouts(): void
    {
        $copy = $this->assign();
        $this->postJson($this->base.'/setup/activate', $this->preview($copy))->assertOk();
        ScheduleEntry::query()->update(['status' => 'completed']);
        $entry = ScheduleEntry::first();
        foreach ([['status' => 'skipped'], ['status' => 'planned', 'started_at' => now()]] as $data) {
            $entry->update($data);
            $this->get('/coach')->assertInertia(fn (Assert $p) => $p->has('upcoming', 0));
        }
        $entry->update(['started_at' => null]);
        ProgramRun::first()->update(['status' => 'paused', 'paused_from' => today()]);
        $this->get('/coach')->assertInertia(fn (Assert $p) => $p->has('upcoming', 0));
    }
}
