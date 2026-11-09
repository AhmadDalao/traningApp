<?php

namespace Tests\Feature;

use App\Models\CoachSubscription;
use App\Models\CoachTraineeRelationship;
use App\Models\ProgramRun;
use App\Models\ScheduleEntry;
use App\Models\TrainingPlan;
use App\Models\User;
use App\Services\CoachSetup;
use App\Services\ProgramSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class IndependentProgramSubscriptionsTest extends TestCase
{
    use RefreshDatabase;

    private User $coach;

    private User $trainee;

    private CoachTraineeRelationship $relationship;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 9)->setTime(14, 0));
        Storage::fake('public');
        $this->coach = User::factory()->create(['account_type' => 'coach']);
        $this->coach->coachProfile()->create(['display_name' => 'First Coach']);
        $this->trainee = User::factory()->create();
        $this->relationship = CoachTraineeRelationship::create(['coach_user_id' => $this->coach->id, 'trainee_user_id' => $this->trainee->id, 'status' => 'active', 'requested_at' => now()]);
        $this->actingAs($this->coach);
    }

    private function template(string $name): TrainingPlan
    {
        $plan = $this->coach->trainingPlans()->create(['name' => $name, 'structure_type' => 'program', 'duration_weeks' => 2]);
        $plan->forceFill(['workspace_type' => 'coach_template'])->save();
        $block = $plan->blocks()->create(['name' => 'Foundation', 'start_week' => 1, 'end_week' => 2, 'position' => 0, 'type' => 'normal']);
        $day = $plan->trainingDays()->create(['training_block_id' => $block->id, 'title' => $name.' workout', 'type' => 'training', 'weekdays' => [1, 3], 'position' => 0]);
        $day->exercises()->create(['name' => 'Push-ups', 'sets' => 3, 'reps' => '10', 'position' => 0]);

        return $plan;
    }

    private function review(TrainingPlan $plan, bool $assigned = false, array $options = []): array
    {
        $service = app(CoachSetup::class);
        $old = $service->publicDraft($this->relationship->fresh());
        $choices = [...$service->defaults($this->relationship), 'selection_type' => $assigned ? 'assigned' : 'template', 'training_plan_id' => $plan->id, 'start_date' => today()->toDateString(), ...$options];
        $draft = $service->save($this->coach, $this->trainee, ['revision' => $old['revision'], 'begin' => $old['completed'], 'choices' => $choices]);
        $review = $service->preview($this->coach, $this->trainee, $draft['revision'], $draft['token']);
        $this->assertTrue($review['valid'], json_encode($review['issues']));

        return ['revision' => $draft['revision'], 'token' => $draft['token'], 'signature' => $review['signature']];
    }

    private function activate(TrainingPlan $plan, bool $assigned = false, array $options = []): array
    {
        $data = $this->review($plan, $assigned, $options);
        $result = app(CoachSetup::class)->activate($this->coach, $this->trainee, $data);
        $this->assertEquals($result, app(CoachSetup::class)->activate($this->coach, $this->trainee, $data));

        return $result;
    }

    public function test_two_programs_start_today_with_independent_coverage_and_preserved_first_schedule(): void
    {
        $first = $this->activate($this->template('Strength'), options: ['open_ended' => true]);
        $before = ScheduleEntry::orderBy('id')->get()->toArray();
        $second = $this->activate($this->template('Mobility'));
        $this->assertNotSame($first['subscription_id'], $second['subscription_id']);
        $this->assertDatabaseCount('coach_subscriptions', 2);
        $this->assertDatabaseCount('coach_plan_assignments', 2);
        $this->assertDatabaseCount('program_runs', 2);
        $this->assertSame($before, ScheduleEntry::orderBy('id')->limit(4)->get()->toArray());
        $this->assertSame(today()->toDateString(), ProgramRun::find($second['program_run_id'])->started_on->toDateString());
        $this->assertNull(CoachSubscription::find($first['subscription_id'])->ends_on);
        $this->assertNotNull(CoachSubscription::find($second['subscription_id'])->ends_on);
        $this->assertSame($second['assignment_id'], CoachSubscription::find($second['subscription_id'])->coach_plan_assignment_id);
    }

    public function test_pause_and_end_only_deny_management_for_the_selected_program(): void
    {
        $a = $this->activate($this->template('Strength'));
        $b = $this->activate($this->template('Mobility'));
        $base = '/coach/trainees/'.$this->trainee->id;
        $planA = ProgramRun::find($a['program_run_id'])->trainingPlan;
        $planB = ProgramRun::find($b['program_run_id'])->trainingPlan;
        $before = ScheduleEntry::orderBy('id')->get()->toArray();
        foreach (['pause', 'end'] as $action) {
            $this->patch('/coach/subscriptions/'.$a['subscription_id'], ['action' => $action, 'confirmed' => true])->assertRedirect();
            $this->putJson($base.'/training-plans/'.$planA->id, ['name' => 'Denied'])->assertForbidden();
            $this->get($base.'/training-plans/'.$planB->id.'/edit')->assertOk();
            $this->postJson($base.'/schedule-entries', ['entry_type' => 'training', 'training_day_id' => $planA->trainingDays()->first()->id, 'scheduled_date' => today()->toDateString()])->assertForbidden();
            $this->get($base.'/calendar')->assertInertia(fn (Assert $p) => $p->where('plans', fn ($rows) => count($rows) === 1 && $rows[0]['id'] === $planB->id));
            $this->assertSame('active', CoachSubscription::find($b['subscription_id'])->status);
        }
        $this->assertSame($before, ScheduleEntry::orderBy('id')->get()->toArray());
    }

    public function test_resume_and_renew_are_scoped_and_never_restart_the_program(): void
    {
        $a = $this->activate($this->template('Strength'));
        $b = $this->activate($this->template('Mobility'));
        $plan = ProgramRun::find($a['program_run_id'])->trainingPlan;
        $before = ScheduleEntry::orderBy('id')->get()->toArray();
        $this->patch('/coach/subscriptions/'.$a['subscription_id'], ['action' => 'pause'])->assertRedirect();
        $this->patch('/coach/subscriptions/'.$a['subscription_id'], ['action' => 'resume'])->assertRedirect();
        $this->assertDatabaseCount('coach_subscriptions', 2);
        $this->patch('/coach/subscriptions/'.$a['subscription_id'], ['action' => 'end', 'confirmed' => true])->assertRedirect();
        $renewed = $this->activate($plan, true);
        $this->assertNotSame($a['subscription_id'], $renewed['subscription_id']);
        $this->assertSame('ended', CoachSubscription::find($a['subscription_id'])->status);
        $this->assertSame($a['assignment_id'], $renewed['assignment_id']);
        $this->assertSame($a['program_run_id'], $renewed['program_run_id']);
        $this->assertSame(0, $renewed['added']);
        $this->assertDatabaseCount('coach_subscriptions', 3);
        $this->assertDatabaseCount('program_runs', 2);
        $this->assertSame($before, ScheduleEntry::orderBy('id')->get()->toArray());
        $this->assertSame('active', CoachSubscription::find($b['subscription_id'])->status);
    }

    public function test_one_coach_cannot_access_another_coachs_program_subscription(): void
    {
        $a = $this->activate($this->template('Strength'));
        $other = User::factory()->create(['account_type' => 'coach']);
        $other->coachProfile()->create(['display_name' => 'Other Coach']);
        CoachTraineeRelationship::create(['coach_user_id' => $other->id, 'trainee_user_id' => $this->trainee->id, 'status' => 'active', 'requested_at' => now()]);
        $this->actingAs($other);
        $this->patch('/coach/subscriptions/'.$a['subscription_id'], ['action' => 'pause'])->assertForbidden();
        $this->get('/coach/trainees/'.$this->trainee->id.'/setup?assignment='.$a['assignment_id'])->assertNotFound();
        $this->get('/coach/subscriptions')->assertInertia(fn (Assert $p) => $p->where('subscriptions.total', 0));
        $this->actingAs($this->trainee)->patch('/coach/subscriptions/'.$a['subscription_id'], ['action' => 'end', 'confirmed' => true])->assertForbidden();
    }

    public function test_same_trainee_can_have_independent_programs_from_two_coaches(): void
    {
        $a = $this->activate($this->template('Coach A strength'));
        $firstCoach = $this->coach;
        $this->coach = User::factory()->create(['account_type' => 'coach']);
        $this->coach->coachProfile()->create(['display_name' => 'Second Coach']);
        $this->relationship = CoachTraineeRelationship::create(['coach_user_id' => $this->coach->id, 'trainee_user_id' => $this->trainee->id, 'status' => 'active', 'requested_at' => now()]);
        $this->actingAs($this->coach);
        $b = $this->activate($this->template('Coach B mobility'));
        $before = ScheduleEntry::orderBy('id')->get()->toArray();
        $this->patch('/coach/subscriptions/'.$a['subscription_id'], ['action' => 'end', 'confirmed' => true])->assertForbidden();
        $this->actingAs($firstCoach)->patch('/coach/subscriptions/'.$a['subscription_id'], ['action' => 'pause'])->assertRedirect();
        $this->assertSame('active', CoachSubscription::find($b['subscription_id'])->status);
        $this->actingAs($this->coach)->get('/coach/subscriptions')->assertInertia(fn (Assert $p) => $p->where('subscriptions.total', 1)->where('subscriptions.data.0.id', $b['subscription_id']));
        $this->actingAs($this->trainee)->get('/my-coaching')->assertInertia(fn (Assert $p) => $p->has('relationships', 2));
        $this->assertSame($before, ScheduleEntry::orderBy('id')->get()->toArray());
    }

    public function test_schedule_uses_own_program_coverage_not_other_ongoing_subscription(): void
    {
        $a = $this->activate($this->template('Finite'));
        $this->activate($this->template('Ongoing'), options: ['open_ended' => true]);
        $plan = ProgramRun::find($a['program_run_id'])->trainingPlan;
        $this->postJson('/coach/trainees/'.$this->trainee->id.'/schedule-entries', ['entry_type' => 'training', 'training_day_id' => $plan->trainingDays()->first()->id, 'scheduled_date' => today()->addMonths(2)->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('scheduled_date');
        $this->assertDatabaseCount('schedule_entries', 8);
    }

    public function test_failed_second_activation_rolls_back_only_new_data(): void
    {
        $first = $this->activate($this->template('Strength'));
        $next = $this->template('Mobility');
        $data = $this->review($next);
        $before = DB::table('coach_subscriptions')->orderBy('id')->get()->toArray();
        $this->partialMock(ProgramSchedule::class, fn ($mock) => $mock->shouldReceive('start')->once()->andThrow(new \RuntimeException('Schedule failed')));
        try {
            app(CoachSetup::class)->activate($this->coach, $this->trainee, $data);
            $this->fail('Activation should fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('Schedule failed', $e->getMessage());
        }
        $this->assertEquals($before, DB::table('coach_subscriptions')->orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('coach_plan_assignments', 1);
        $this->assertDatabaseCount('program_runs', 1);
        $this->assertDatabaseCount('schedule_entries', 4);
        $this->assertDatabaseCount('training_plans', 3);
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    public function test_subscription_filters_pagination_and_program_identity(): void
    {
        $a = $this->activate($this->template('Strength'));
        $this->activate($this->template('Mobility'));
        $this->patch('/coach/subscriptions/'.$a['subscription_id'], ['action' => 'pause'])->assertRedirect();
        $this->get('/coach/subscriptions?status=active&search=Mobility&per_page=25')->assertInertia(fn (Assert $p) => $p->where('subscriptions.total', 1)->where('subscriptions.per_page', 25)->where('subscriptions.data.0.plan.name', 'Mobility')->where('counts.paused', 1)->where('counts.active', 1));
        $this->get('/coach/subscriptions')->assertInertia(fn (Assert $p) => $p->where('subscriptions.per_page', 10));
        $this->get('/coach/training-plans?per_page=10&search=Strength')->assertInertia(fn (Assert $p) => $p->where('plans.total', 1)->where('plans.per_page', 10));
        $this->actingAs($this->trainee)->get('/my-coaching')->assertInertia(fn (Assert $p) => $p->where('relationships.0.subscriptions', fn ($rows) => collect($rows)->pluck('training.name')->sort()->values()->all() === ['Mobility', 'Strength']));
    }

    public function test_new_assignment_defaults_to_today_and_settings_keep_workspace(): void
    {
        $this->activate($this->template('Strength'), options: ['start_date' => today()->addMonth()->toDateString()]);
        $this->get('/coach/trainees/'.$this->trainee->id.'/setup?assign=1')->assertInertia(fn (Assert $p) => $p->where('draft.choices.start_date', today()->toDateString()));
        $this->get('/coach/settings/notifications')->assertInertia(fn (Assert $p) => $p->where('trainingContext.workspace', 'coach'));
        $this->get('/profile/notifications')->assertInertia(fn (Assert $p) => $p->where('trainingContext.workspace', 'personal'));
    }

    public function test_migration_splits_legacy_shared_coverage_without_changing_training(): void
    {
        $a = $this->activate($this->template('Strength'));
        $b = $this->activate($this->template('Mobility'));
        DB::table('coach_plan_assignments')->where('id', $b['assignment_id'])->update(['coach_subscription_id' => $a['subscription_id']]);
        DB::table('coach_subscriptions')->where('id', $b['subscription_id'])->delete();
        $before = ScheduleEntry::orderBy('id')->get()->toArray();
        $migration = require database_path('migrations/2026_11_09_000016_scope_subscriptions_to_program_assignments.php');
        DB::table('coach_subscriptions')->update(['coach_plan_assignment_id' => null]);
        $migration->separateCoverage();
        $this->assertDatabaseCount('coach_subscriptions', 2);
        $subscriptions = CoachSubscription::orderBy('id')->get();
        $this->assertNotSame($subscriptions[0]->coach_plan_assignment_id, $subscriptions[1]->coach_plan_assignment_id);
        $this->assertEquals($subscriptions[0]->only('package_name', 'starts_on', 'ends_on', 'status', 'notes'), $subscriptions[1]->only('package_name', 'starts_on', 'ends_on', 'status', 'notes'));
        $this->assertSame($before, ScheduleEntry::orderBy('id')->get()->toArray());
    }
}
