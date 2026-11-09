<?php

namespace Tests\Feature;

use App\Models\CoachTraineeRelationship;
use App\Models\ProgramRun;
use App\Models\TrainingPlan;
use App\Models\User;
use App\Services\ProgramSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CoachDesktopWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $coach;

    private User $trainee;

    private CoachTraineeRelationship $relationship;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 9)->setTime(8, 0));
        Storage::fake('public');
        $this->coach = User::factory()->create(['account_type' => 'coach']);
        $this->coach->coachProfile()->create(['display_name' => 'Maya Hassan']);
        $this->trainee = User::factory()->create(['name' => 'Sarah Ahmed']);
        $this->relationship = CoachTraineeRelationship::create(['coach_user_id' => $this->coach->id, 'trainee_user_id' => $this->trainee->id, 'status' => 'active', 'requested_at' => now(), 'approved_at' => now()]);
        $this->relationship->subscriptions()->create(['package_name' => 'Ongoing coaching', 'starts_on' => today(), 'status' => 'active']);
        $this->base = '/coach/trainees/'.$this->trainee->id;
        $this->actingAs($this->coach);
    }

    private function plan(string $name = 'Strength', bool $program = true): TrainingPlan
    {
        $plan = $this->trainee->trainingPlans()->create(['name' => $name, 'structure_type' => $program ? 'program' : 'simple', 'duration_weeks' => $program ? 12 : null]);
        $plan->forceFill(['workspace_type' => 'coach_assigned'])->save();
        $this->relationship->assignments()->create(['assigned_training_plan_id' => $plan->id, 'coach_subscription_id' => $this->relationship->subscriptions->first()->id, 'status' => 'active', 'assigned_at' => now()]);
        foreach ($program ? range(0, 2) : [0] as $blockIndex) {
            $block = $program ? $plan->blocks()->create(['name' => ['Foundation', 'Build', 'Peak'][$blockIndex], 'start_week' => $blockIndex * 4 + 1, 'end_week' => $blockIndex * 4 + 4, 'position' => $blockIndex, 'type' => 'normal']) : null;
            foreach (['Upper Body', 'Lower Body', 'Conditioning', 'Full Body', 'Mobility'] as $position => $title) {
                $day = $plan->trainingDays()->create(['training_block_id' => $block?->id, 'title' => $title, 'type' => 'training', 'weekdays' => [$position], 'position' => $position]);
                $day->exercises()->create(['name' => 'Push-up', 'sets' => 3 + $blockIndex, 'reps' => '10', 'position' => 0]);
            }
        }

        return $plan;
    }

    private function start(TrainingPlan $plan, int $days = 0): ProgramRun
    {
        $service = app(ProgramSchedule::class);
        $input = ['start_date' => today()->addDays($days)->toDateString(), 'nonce' => (string) Str::uuid()];
        $preview = $service->preview($plan, $input['start_date'], $input['nonce'], $this->coach);
        $paths = [];

        return DB::transaction(fn () => $service->start($plan, [...$input, 'signature' => $preview['signature']], $this->coach, $paths, false));
    }

    public function test_upcoming_groups_count_the_entire_window_without_repeating_trainees(): void
    {
        $plan = $this->plan(program: false);
        $day = $plan->trainingDays->first();
        foreach (range(1, 20) as $i) {
            $this->trainee->scheduleEntries()->create(['training_day_id' => $day->id, 'scheduled_by_user_id' => $this->coach->id, 'title' => 'Upper Body '.$i, 'entry_type' => 'training', 'status' => 'planned', 'scheduled_date' => today()->addDays(($i % 7) + 1)]);
        }
        $this->get('/coach')->assertInertia(fn (Assert $p) => $p->has('upcomingGroups', 1)->where('upcomingGroups.0.user_id', $this->trainee->id)->where('upcomingGroups.0.session_count', 20)->has('todaySessions', 0));
    }

    public function test_overview_dates_remain_application_local_calendar_dates(): void
    {
        $day = $this->plan(program: false)->trainingDays->first();
        $this->trainee->scheduleEntries()->create(['training_day_id' => $day->id, 'scheduled_by_user_id' => $this->coach->id, 'title' => 'Today workout', 'entry_type' => 'training', 'status' => 'planned', 'scheduled_date' => today()]);
        $this->get('/coach')->assertInertia(fn (Assert $p) => $p->where('todaySessions.0.scheduled_date', '2026-11-09')->where('todaySessions.0.scheduled_time', null));
    }

    public function test_current_training_stays_consistent_with_future_and_historical_assignments(): void
    {
        $current = $this->plan('Current strength');
        $run = $this->start($current);
        $future = $this->plan('Next program');
        $this->start($future, 90);
        $past = $this->plan('Finished program');
        $old = $this->start($past, 180);
        $old->sessions()->update(['status' => 'completed']);
        $old->update(['status' => 'completed']);
        foreach (['', '/training-plans', '/progress'] as $page) {
            $this->get($this->base.$page)->assertInertia(fn (Assert $p) => $p->where('currentTraining.id', $current->id)->where('currentTraining.run_id', $run->id)->where('trainingContext.summary.plan.id', $current->id));
        }
        $this->assertDatabaseCount('program_runs', 3);
    }

    public function test_one_off_choices_are_authored_days_grouped_by_block_not_sixty_occurrences(): void
    {
        $plan = $this->plan();
        $run = $this->start($plan);
        $this->assertSame(60, $run->sessions()->count());
        $this->get($this->base.'/setup')->assertInertia(fn (Assert $p) => $p->where('assignedPlans.0.weekly_frequency.min', 5)->where('assignedPlans.0.weekly_frequency.max', 5));
        $this->get($this->base.'/training-plans')->assertInertia(fn (Assert $p) => $p->where('plans.data.0.weekly_frequency.min', 5));
        $this->get($this->base.'/schedule-entries/create')->assertInertia(fn (Assert $p) => $p->has('plans', 1)->has('plans.0.training_days', 15)->has('plans.0.workout_blocks', 3)->where('plans.0.default_block_id', $plan->blocks->first()->id));
        $day = $plan->trainingDays->first();
        $this->post($this->base.'/schedule-entries', ['training_day_id' => $day->id, 'entry_type' => 'training', 'scheduled_date' => today()->toDateString()])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(61, $this->trainee->scheduleEntries()->count());
        $manual = $this->trainee->scheduleEntries()->latest('id')->first();
        $this->assertNull($manual->program_run_id);
        $this->assertNull($manual->plan_occurrence_key);
    }

    public function test_schedule_only_update_preserves_exercises_images_and_calendar_until_review(): void
    {
        $plan = $this->plan(program: false);
        $day = $plan->trainingDays->first();
        $exercise = $day->exercises->first();
        Storage::disk('public')->put('original.jpg', 'image');
        $exercise->update(['thumbnail_path' => 'original.jpg']);
        $before = $exercise->fresh()->getAttributes();
        $this->putJson($this->base.'/training-days/'.$day->id, ['schedule_only' => true, 'weekdays' => [2, 4], 'default_time' => '19:00', 'expected_weekdays' => [0], 'expected_default_time' => null])->assertOk();
        $this->assertSame($before, $exercise->fresh()->getAttributes());
        $this->assertSame([2, 4], $day->fresh()->weekdays);
        $this->assertDatabaseCount('schedule_entries', 0);
        Storage::disk('public')->assertExists('original.jpg');
    }

    public function test_schedule_editor_rejects_stale_choices_and_non_schedule_fields(): void
    {
        $day = $this->plan(program: false)->trainingDays->first();
        $input = ['schedule_only' => true, 'weekdays' => [2], 'default_time' => null, 'expected_weekdays' => [0], 'expected_default_time' => null];
        $this->putJson($this->base.'/training-days/'.$day->id, [...$input, 'exercises' => [['name' => 'Forged']]])->assertUnprocessable();
        $day->update(['weekdays' => [4]]);
        $this->putJson($this->base.'/training-days/'.$day->id, $input)->assertUnprocessable()->assertJsonValidationErrors('schedule');
        $this->assertSame([4], $day->fresh()->weekdays);
    }

    public function test_current_block_stays_protected_but_future_block_can_change_without_moving_sessions(): void
    {
        $plan = $this->plan();
        $run = $this->start($plan);
        $before = $run->sessions()->get()->toJson();
        $days = $plan->trainingDays()->where('position', 0)->orderBy('training_block_id')->get();
        $input = ['schedule_only' => true, 'weekdays' => [6], 'default_time' => null, 'expected_weekdays' => [0], 'expected_default_time' => null];
        $this->putJson($this->base.'/training-days/'.$days[0]->id, $input)->assertForbidden();
        $this->putJson($this->base.'/training-days/'.$days[1]->id, $input)->assertOk();
        $this->assertSame($before, $run->sessions()->get()->toJson());
    }

    public function test_schedule_editor_and_grouped_overview_do_not_leak_other_coach_or_personal_training(): void
    {
        $day = $this->plan(program: false)->trainingDays->first();
        $other = User::factory()->create(['account_type' => 'coach']);
        $other->coachProfile()->create(['display_name' => 'Other coach']);
        $this->actingAs($other)->putJson($this->base.'/training-days/'.$day->id, ['schedule_only' => true, 'weekdays' => [2], 'expected_weekdays' => [0]])->assertNotFound();
        $this->get('/coach')->assertInertia(fn (Assert $p) => $p->has('upcomingGroups', 0));
        $this->actingAs($this->trainee)->get($this->base.'/schedule-entries/create')->assertForbidden();
    }
}
