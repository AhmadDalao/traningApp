<?php

namespace Tests\Feature;

use App\Models\CoachTraineeRelationship;
use App\Models\ScheduleEntry;
use App\Models\TrainingPlan;
use App\Models\User;
use App\Services\CoachSetup;
use App\Services\TrainingPlanDuplicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CoachJourneyTest extends TestCase
{
    use RefreshDatabase;

    private User $coach;

    private User $trainee;

    private CoachTraineeRelationship $relationship;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->coach = User::factory()->create(['account_type' => 'coach']);
        $this->coach->coachProfile()->create(['display_name' => 'Coach']);
        $this->trainee = User::factory()->create();
        $this->relationship = CoachTraineeRelationship::create(['coach_user_id' => $this->coach->id, 'trainee_user_id' => $this->trainee->id, 'status' => 'active', 'requested_at' => now()]);
        $this->actingAs($this->coach);
    }

    private function template(string $name = 'Strength'): array
    {
        $plan = $this->postJson('/coach/training-plans', ['name' => $name, 'structure_type' => 'program', 'duration_weeks' => 2])->assertCreated()->json('plan');
        $day = $this->postJson('/coach/training-plans/'.$plan['id'].'/days', ['training_block_id' => $plan['blocks'][0]['id'], 'title' => 'Full Body', 'type' => 'training', 'weekdays' => [1, 3], 'exercises' => [['name' => 'Push-up', 'sets' => 3, 'reps' => '10']]])->assertCreated()->json('day');

        return [$plan, $day];
    }

    private function activate(int $template, bool $begin = false): array
    {
        $base = '/coach/trainees/'.$this->trainee->id.'/setup';
        $draft = app(CoachSetup::class)->publicDraft($this->relationship->fresh());
        $choices = [...app(CoachSetup::class)->defaults($this->relationship), 'selection_type' => 'template', 'training_plan_id' => $template, 'start_date' => today()->addWeek()->toDateString(), 'open_ended' => true];
        $saved = $this->patchJson($base.'/draft', ['revision' => $draft['revision'], 'begin' => $begin, 'choices' => $choices])->assertOk()->json('draft');
        $input = ['revision' => $saved['revision'], 'token' => $saved['token']];
        $preview = $this->postJson($base.'/preview', $input)->assertOk()->json('preview');
        $this->assertTrue($preview['valid']);
        $input += ['signature' => $preview['signature'], 'confirmed' => true];
        $this->postJson($base.'/activate', $input)->assertOk();
        $this->postJson($base.'/activate', $input)->assertOk();

        return $input;
    }

    public function test_program_builder_saves_reusable_training_and_embedded_day_without_assignment(): void
    {
        $this->get('/coach/training-plans/create?assign_to='.$this->trainee->id)->assertInertia(fn (Assert $p) => $p->component('Coach/ProgramBuilder')->where('assignTo.id', $this->trainee->id));
        [$plan, $day] = $this->template();
        $this->assertDatabaseHas('training_plans', ['id' => $plan['id'], 'user_id' => $this->coach->id, 'workspace_type' => 'coach_template']);
        foreach (['coach_subscriptions', 'coach_plan_assignments', 'program_runs', 'schedule_entries'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->putJson('/coach/training-days/'.$day['id'], ['title' => 'Full Body Updated', 'type' => 'training', 'weekdays' => [0, 2], 'exercises' => [['id' => $day['exercises'][0]['id'], 'name' => 'Push-up', 'sets' => 4, 'reps' => '12']]])->assertOk()->assertJsonPath('day.exercises.0.sets', 4);
        $this->get('/coach/training-plans/'.$plan['id'].'/edit')->assertInertia(fn (Assert $p) => $p->component('Coach/ProgramBuilder')->where('plan.training_days.0.title', 'Full Body Updated'));
    }

    public function test_program_entry_goes_directly_to_schedule_without_active_writes(): void
    {
        [$plan] = $this->template();
        $base = '/coach/trainees/'.$this->trainee->id;
        $this->get($base.'/assign?template='.$plan['id'])->assertRedirect($base.'/setup?assign=1&template='.$plan['id']);
        $this->get($base.'/setup?template='.$plan['id'])->assertInertia(fn (Assert $p) => $p->where('startAtSchedule', true)->where('draft.choices.training_plan_id', $plan['id']));
        $this->assertNull($this->relationship->fresh()->setup_draft);
        $this->assertDatabaseCount('coach_plan_assignments', 0);
        $this->assertDatabaseCount('schedule_entries', 0);
    }

    public function test_a_new_program_can_follow_completed_setup_without_reusing_the_old_token_or_subscription(): void
    {
        [$one] = $this->template('First');
        $old = $this->activate($one['id']);
        $originalSessions = ScheduleEntry::orderBy('id')->get()->toArray();
        [$two] = $this->template('Second');
        $next = $this->activate($two['id'], true);
        $this->assertNotSame($old['token'], $next['token']);
        $this->assertDatabaseCount('coach_subscriptions', 1);
        $this->assertDatabaseCount('coach_plan_assignments', 2);
        $this->assertDatabaseCount('program_runs', 2);
        $this->assertDatabaseCount('schedule_entries', 8);
        $this->assertSame($originalSessions, ScheduleEntry::orderBy('id')->limit(4)->get()->toArray());
        $this->postJson('/coach/trainees/'.$this->trainee->id.'/setup/activate', $old)->assertStatus(409);
        $this->get('/coach/training-plans/'.$two['id'].'/assign')->assertInertia(fn (Assert $p) => $p->where('relationships.data.0.existing_plan_id', $this->relationship->assignments()->latest('id')->first()->assigned_training_plan_id));
    }

    public function test_settings_notifications_and_account_updates_preserve_coach_workspace(): void
    {
        foreach (['/coach/settings', '/coach/settings/account', '/coach/notifications'] as $url) {
            $this->get($url)->assertInertia(fn (Assert $p) => $p->where('trainingContext.workspace', 'coach')->where('trainingContext.coaching', false));
        }
        $this->post('/coach/settings', ['display_name' => 'Updated Coach'])->assertRedirect('/coach/settings');
        $this->put('/coach/settings/account', ['name' => 'Updated Account'])->assertRedirect('/coach/settings');
        $this->get('/profile')->assertInertia(fn (Assert $p) => $p->where('trainingContext.workspace', 'personal'));
        $this->actingAs($this->trainee)->get('/coach/settings')->assertForbidden();
        $this->post('/coach/settings', ['display_name' => 'Forged'])->assertForbidden();
    }

    public function test_builder_and_selection_reject_foreign_resources_and_unconnected_trainees(): void
    {
        [$plan] = $this->template();
        $other = User::factory()->create();
        $this->get('/coach/training-plans/create?assign_to='.$other->id)->assertNotFound();
        $private = $this->trainee->trainingPlans()->create(['name' => 'Private']);
        $this->get('/coach/trainees/'.$this->trainee->id.'/setup?template='.$private->id)->assertNotFound();
        $this->putJson('/coach/training-plans/'.$private->id, ['name' => 'Changed'])->assertForbidden();
        $this->actingAs($this->trainee)->get('/coach/training-plans/'.$plan['id'].'/edit')->assertForbidden();
    }

    public function test_returning_to_selected_template_preserves_the_saved_schedule_draft(): void
    {
        [$plan, $day] = $this->template();
        $choices = [...app(CoachSetup::class)->defaults($this->relationship), 'training_plan_id' => $plan['id'], 'start_date' => today()->addWeeks(3)->toDateString(), 'day_options' => [$day['id'] => ['weekdays' => [2, 4], 'time' => '19:30']]];
        $saved = app(CoachSetup::class)->save($this->coach, $this->trainee, ['revision' => 0, 'choices' => $choices]);
        $this->get('/coach/trainees/'.$this->trainee->id.'/setup?template='.$plan['id'])->assertInertia(fn (Assert $p) => $p->where('draft.choices.start_date', $choices['start_date'])->where('draft.choices.day_options.'.$day['id'].'.time', '19:30')->where('draft.revision', $saved['revision']));
        $this->assertDatabaseCount('coach_plan_assignments', 0);
    }

    public function test_journey_query_counts_remain_bounded_with_many_trainees_and_a_large_program(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $trainee = User::factory()->create();
            CoachTraineeRelationship::create(['coach_user_id' => $this->coach->id, 'trainee_user_id' => $trainee->id, 'status' => 'active', 'requested_at' => now()]);
        }
        [$definition, $day] = $this->template();
        $plan = TrainingPlan::findOrFail($definition['id']);
        $plan->update(['duration_weeks' => 12]);
        $plan->blocks()->update(['end_week' => 12]);
        $plan->trainingDays()->update(['weekdays' => '[1,2,4,6]']);
        for ($i = 0; $i < 19; $i++) {
            $plan->trainingDays()->first()->exercises()->create(['name' => 'Exercise '.$i, 'sets' => 3, 'reps' => '10', 'position' => $i + 1]);
        }
        $metrics = [];
        $measure = function ($label, $request) use (&$metrics) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $start = hrtime(true);
            $response = $request();
            $metrics[$label] = ['queries' => count(DB::getQueryLog()), 'milliseconds' => round((hrtime(true) - $start) / 1e6, 2)];
            DB::disableQueryLog();
            $response->assertOk();

            return $response;
        };
        foreach (['programs' => '/coach/training-plans', 'builder' => '/coach/training-plans/'.$plan->id.'/edit', 'choose_trainee' => '/coach/training-plans/'.$plan->id.'/assign', 'schedule' => '/coach/trainees/'.$this->trainee->id.'/setup?template='.$plan->id, 'overview' => '/coach/trainees/'.$this->trainee->id] as $label => $url) {
            $measure($label, fn () => $this->get($url));
            $this->assertLessThan(40, $metrics[$label]['queries'], $label.' query count');
        }
        $choices = [...app(CoachSetup::class)->defaults($this->relationship), 'training_plan_id' => $plan->id, 'start_date' => today()->addWeek()->toDateString(), 'open_ended' => true];
        $saved = app(CoachSetup::class)->save($this->coach, $this->trainee, ['revision' => 0, 'choices' => $choices]);
        $preview = $measure('preview', fn () => $this->postJson('/coach/trainees/'.$this->trainee->id.'/setup/preview', ['revision' => $saved['revision'], 'token' => $saved['token']]))->json('preview');
        $this->assertTrue($preview['valid']);
        $this->assertCount(48, $preview['sessions']);
        $this->assertLessThan(45, $metrics['preview']['queries']);
        $measure('activation', fn () => $this->postJson('/coach/trainees/'.$this->trainee->id.'/setup/activate', ['revision' => $saved['revision'], 'token' => $saved['token'], 'signature' => $preview['signature'], 'confirmed' => true]));
        $this->assertDatabaseCount('schedule_entries', 48);
        $this->assertDatabaseCount('program_runs', 1);
        foreach (['coach_overview' => '/coach', 'trainees' => '/coach/trainees', 'trainee_overview_active' => '/coach/trainees/'.$this->trainee->id, 'training' => '/coach/trainees/'.$this->trainee->id.'/training-plans', 'calendar' => '/coach/trainees/'.$this->trainee->id.'/calendar', 'history' => '/coach/trainees/'.$this->trainee->id.'/training-history', 'progress' => '/coach/trainees/'.$this->trainee->id.'/progress'] as $label => $url) {
            $measure($label, fn () => $this->get($url));
            $this->assertLessThan(75, $metrics[$label]['queries'], $label.' bounded query count');
        }
        if ($output = getenv('QA_METRICS_FILE')) {
            file_put_contents($output, json_encode($metrics, JSON_PRETTY_PRINT));
        }
    }

    public function test_program_card_counts_distinct_trainees_when_legacy_copies_exist(): void
    {
        [$definition] = $this->template();
        $plan = TrainingPlan::findOrFail($definition['id']);
        $this->relationship->subscriptions()->create(['package_name' => 'Ongoing', 'starts_on' => today(), 'status' => 'active']);
        for ($i = 0; $i < 2; $i++) {
            app(TrainingPlanDuplicator::class)->assign($this->coach, $this->trainee, $plan, [], notify: false);
        }
        $this->get('/coach/training-plans')->assertInertia(fn (Assert $p) => $p->where('plans.data.0.assigned_trainees_count', 1));
        $this->get('/coach/trainees/'.$this->trainee->id.'/setup?assign=1')->assertInertia(fn (Assert $p) => $p->where('existingAssignments.'.$plan->id, $this->relationship->assignments()->latest('id')->first()->assigned_training_plan_id));
    }
}
