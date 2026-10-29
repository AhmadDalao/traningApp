<?php

namespace Tests\Feature;

use App\Models\CoachTraineeRelationship;
use App\Models\ScheduleEntry;
use App\Models\TrainingPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CalendarLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');
        $this->travelTo(now()->setDate(2026, 10, 29)->setTime(12, 0));
    }

    private function register(string $name, string $role = 'trainee'): User
    {
        if (auth()->check()) {
            $this->post('/logout');
        }
        $this->post('/register', ['name' => $name, 'email' => $name.'@example.test', 'password' => 'Calendar-lifecycle-123', 'password_confirmation' => 'Calendar-lifecycle-123', 'account_type' => $role, 'display_name' => $name])->assertSessionHasNoErrors()->assertRedirect($role === 'coach' ? '/coach' : '/dashboard');

        return User::where('email', $name.'@example.test')->firstOrFail();
    }

    private function props(string $path): array
    {
        return $this->get($path)->assertOk()->viewData('page')['props'];
    }

    private function dayFields(): array
    {
        return ['title' => 'Strength', 'type' => 'training', 'focus_area' => 'Legs', 'weekdays' => [1, 3, 5], 'default_time' => '18:00', 'exercises' => [['name' => 'Squat', 'sets' => 2, 'reps' => '8-10', 'target_weight' => 40, 'tracking_mode' => 'reps', 'library_key' => 'builtin:Barbell_Full_Squat', 'position' => 0]]];
    }

    private function plan(string $base = ''): TrainingPlan
    {
        $this->post($base.'/training-plans', ['name' => 'Lifecycle plan', 'schedule_mode' => 'ongoing', 'start_date' => '2026-11-01'])->assertSessionHasNoErrors();
        $plan = TrainingPlan::latest('id')->firstOrFail();
        $this->post($base.'/training-plans/'.$plan->id.'/days', $this->dayFields())->assertSessionHasNoErrors();

        return $plan;
    }

    private function activate(TrainingPlan $plan, string $base = ''): void
    {
        $preview = $this->props($base.'/training-plans/'.$plan->id.'/schedule-preview')['preview'];
        $this->post($base.'/training-plans/'.$plan->id.'/schedule', ['signature' => $preview['signature'], 'action' => 'add', 'confirmed' => true])->assertSessionHasNoErrors();
        $this->assertTrue($plan->fresh()->schedule_enabled);
    }

    private function occurrence(TrainingPlan $plan, string $date): ScheduleEntry
    {
        return ScheduleEntry::where('training_day_id', $plan->trainingDays()->firstOrFail()->id)->whereDate('scheduled_date', $date)->firstOrFail();
    }

    private function move(ScheduleEntry $entry, string $to, string $scope = 'one', string $base = ''): void
    {
        $before = $entry->fresh()->getAttributes();
        $data = ['scheduled_date' => $to, 'scheduled_time' => substr($entry->scheduled_time ?? '', 0, 5), 'scope' => $scope];
        $url = $base.'/schedule-entries/'.$entry->id.'/reschedule';
        $preview = $this->postJson($url.'-preview', $data)->assertOk()->json('preview');
        $this->assertSame($before, $entry->fresh()->getAttributes());
        $this->postJson($url, [...$data, 'signature' => $preview['signature'], 'confirmed' => true])->assertOk()->assertJsonPath('saved', true);
        $this->assertSame($to, $entry->fresh()->scheduled_date->toDateString());
    }

    private function complete(ScheduleEntry $entry): array
    {
        $this->post('/schedule-entries/'.$entry->id.'/workout/start')->assertRedirect('/schedule-entries/'.$entry->id.'/workout');
        $exercise = $entry->sessionExercises()->firstOrFail();
        foreach ($exercise->sets as $set) {
            $this->patch('/session-sets/'.$set->id, ['actual_reps' => 10, 'actual_weight_kg' => 40, 'completed' => true])->assertSessionHasNoErrors();
        }
        $this->postJson('/schedule-entries/'.$entry->id.'/reschedule-preview', ['scheduled_date' => '2026-11-12', 'scope' => 'one'])->assertForbidden();
        $this->post('/schedule-entries/'.$entry->id.'/workout/finish')->assertSessionHasNoErrors();
        $this->assertSame('completed', $entry->fresh()->status);

        return [$entry->fresh()->getAttributes(), $exercise->fresh()->getAttributes(), $exercise->sets()->orderBy('id')->get()->map->getAttributes()->all()];
    }

    public function test_personal_registration_to_stopped_schedule_preserves_completed_workout_results(): void
    {
        $user = $this->register('personal-cycle');
        $this->post('/onboarding', ['height_cm' => 178, 'current_weight_kg' => 82, 'training_goals' => ['get_stronger'], 'experience_level' => 'beginner', 'training_days_per_week' => 3, 'session_duration_minutes' => 60, 'equipment_access' => ['full_gym']])->assertSessionHasNoErrors()->assertRedirect('/recommendations');
        $this->assertNotNull($user->fresh()->profile->onboarding_completed_at);
        $plan = $this->plan();
        $this->props('/calendar');
        $this->assertDatabaseCount('schedule_entries', 0);
        $this->activate($plan);
        $day = $plan->trainingDays()->firstOrFail();
        $this->post('/schedule-entries', ['training_day_id' => $day->id, 'entry_type' => 'training', 'title' => 'Manual extra', 'scheduled_date' => '2026-11-02', 'scheduled_time' => '15:00'])->assertSessionHasNoErrors();
        $manual = ScheduleEntry::latest('id')->firstOrFail();
        $this->move($manual, '2026-11-30');
        $this->assertNull($manual->fresh()->plan_occurrence_key);
        $this->assertSame([1, 3, 5], $day->fresh()->weekdays);
        $single = $this->occurrence($plan, '2026-11-02');
        $this->move($single, '2026-11-03');
        $this->move($this->occurrence($plan, '2026-11-09'), '2026-11-10', 'future');
        $this->assertSame([2, 3, 5], $day->fresh()->weekdays);
        $this->props('/calendar');
        $this->activate($plan);
        $this->assertDatabaseMissing('schedule_entries', ['training_day_id' => $day->id, 'scheduled_date' => '2026-11-02']);
        $this->travelTo(now()->setDate(2026, 11, 3)->setTime(19, 0));
        $frozen = $this->complete($single);
        $history = $this->props('/training-history?period=today');
        $this->assertSame(1, $history['summary']['completed']);
        $this->assertEquals($single->id, $history['entries']['data'][0]['id']);
        $this->props('/progress');
        $this->props('/progress/exercises?exercise=builtin%3ABarbell_Full_Squat');
        $url = '/training-plans/'.$plan->id.'/stop-schedule';
        $preview = $this->postJson($url.'-preview', ['end_date' => '2026-11-15'])->assertOk()->json('preview');
        $this->assertNotEmpty($preview['remove']);
        $this->postJson($url, ['end_date' => '2026-11-15', 'signature' => $preview['signature'], 'confirmed' => true])->assertOk();
        $this->props('/calendar');
        $exercise = $single->sessionExercises()->firstOrFail();
        $this->assertSame($frozen, [$single->fresh()->getAttributes(), $exercise->fresh()->getAttributes(), $exercise->sets()->orderBy('id')->get()->map->getAttributes()->all()]);
        $this->assertNotNull($manual->fresh());
        $this->assertSame('2026-11-15', $plan->fresh()->end_date->toDateString());
        $this->assertSame(0, ScheduleEntry::where('schedule_managed', true)->whereDate('scheduled_date', '>', '2026-11-15')->count());
        $this->post('/profile/coach', ['display_name' => 'Forbidden'])->assertForbidden();
        $this->post('/workspace', ['workspace' => 'coach'])->assertForbidden();
    }

    public function test_coach_and_trainee_lifecycle_preserves_context_history_and_rejects_revoked_preview(): void
    {
        $coach = $this->register('coach-cycle', 'coach');
        $trainee = $this->register('coached-cycle');
        $this->actingAs($coach)->post('/coach/requests', ['training_code' => $trainee->training_code])->assertSessionHasNoErrors();
        $relationship = CoachTraineeRelationship::firstOrFail();
        $this->actingAs($trainee)->post('/my-coaching/relationships/'.$relationship->id, ['action' => 'accept'])->assertSessionHasNoErrors();
        $this->actingAs($coach)->post('/coach/subscription-plans', ['name' => 'Lifecycle service', 'duration_days' => 33, 'active' => true])->assertSessionHasNoErrors();
        $package = $this->props('/coach/subscriptions')['packages'][0];
        $this->post('/coach/subscriptions', ['relationship_id' => $relationship->id, 'package_id' => $package['id'], 'starts_on' => '2026-10-29'])->assertSessionHasNoErrors();
        $subscription = $relationship->subscriptions()->firstOrFail();
        $base = '/coach/trainees/'.$trainee->id;
        $template = $this->plan('/coach');
        $this->post($base.'/assign', ['training_plan_id' => $template->id, 'start_date' => '2026-11-01', 'schedule_mode' => 'ongoing', 'confirmed' => true])->assertSessionHasNoErrors();
        $plan = $trainee->trainingPlans()->firstOrFail();
        $this->assertNull($plan->end_date);
        $this->activate($plan, $base);
        $this->assertSame(0, ScheduleEntry::whereDate('scheduled_date', '>', '2026-11-30')->count());
        $single = $this->occurrence($plan, '2026-11-02');
        $this->move($single, '2026-11-03', 'one', $base);
        $this->move($this->occurrence($plan, '2026-11-09'), '2026-11-10', 'future', $base);
        $this->assertSame([2, 3, 5], $plan->trainingDays()->first()->weekdays);
        $sourceDay = $template->trainingDays()->first();
        $this->put('/coach/training-days/'.$sourceDay->id, [...$this->dayFields(), 'title' => 'Changed source', 'weekdays' => [6]])->assertSessionHasNoErrors();
        $this->assertSame([2, 3, 5], $plan->trainingDays()->first()->weekdays);
        $this->assertSame('Strength', $plan->trainingDays()->first()->title);
        // Rest sessions must come from an assigned day in coach context.
        $this->post($base.'/schedule-entries', ['entry_type' => 'rest', 'scheduled_date' => '2026-11-05'])->assertSessionHasErrors('training_day_id');
        $this->travelTo(now()->setDate(2026, 11, 3)->setTime(19, 0));
        $this->actingAs($trainee);
        $frozen = $this->complete($single);
        $this->actingAs($coach);
        $details = $this->props($base.'/schedule-entries/'.$single->id);
        $this->assertFalse($details['canMove']);
        $this->assertSame($coach->id, $details['auth']['user']['id']);
        $this->assertSame(1, $this->props($base.'/training-history')['summary']['completed']);
        $this->props($base.'/progress/exercises?exercise=builtin%3ABarbell_Full_Squat');
        $selected = $this->occurrence($plan, '2026-11-17');
        $url = $base.'/schedule-entries/'.$selected->id.'/reschedule';
        $data = ['scheduled_date' => '2026-11-19', 'scheduled_time' => '18:00', 'scope' => 'future'];
        $preview = $this->postJson($url.'-preview', $data)->assertOk()->json('preview');
        $before = $selected->getAttributes();
        $this->patch('/coach/subscriptions/'.$subscription->id, ['action' => 'pause'])->assertSessionHasNoErrors();
        $this->postJson($url, [...$data, 'signature' => $preview['signature'], 'confirmed' => true])->assertForbidden();
        $this->assertSame($before, $selected->fresh()->getAttributes());
        $this->patch('/coach/subscriptions/'.$subscription->id, ['action' => 'resume'])->assertSessionHasNoErrors();
        $this->move($selected, '2026-11-19', 'future', $base);
        $this->actingAs($trainee)->post('/my-coaching/relationships/'.$relationship->id, ['action' => 'end', 'confirmed' => true])->assertSessionHasNoErrors();
        $all = ScheduleEntry::orderBy('id')->get()->map->getAttributes()->all();
        $this->actingAs($coach)->postJson($url.'-preview', $data)->assertNotFound();
        $this->postJson($url, [...$data, 'signature' => $preview['signature'], 'confirmed' => true])->assertNotFound();
        $this->actingAs($trainee)->get('/calendar')->assertOk();
        $this->assertSame($all, ScheduleEntry::orderBy('id')->get()->map->getAttributes()->all());
        $exercise = $single->sessionExercises()->firstOrFail();
        $this->assertSame($frozen, [$single->fresh()->getAttributes(), $exercise->fresh()->getAttributes(), $exercise->sets()->orderBy('id')->get()->map->getAttributes()->all()]);
        // A coach's own Personal Workspace remains ordinary owner-scoped scheduling.
        $this->actingAs($coach)->post('/workspace', ['workspace' => 'personal'])->assertRedirect('/dashboard');
        $this->post('/schedule-entries', ['entry_type' => 'rest', 'title' => 'Own rest', 'scheduled_date' => '2026-11-05'])->assertSessionHasNoErrors();
        $own = $coach->scheduleEntries()->firstOrFail();
        $this->move($own, '2026-11-06');
        $this->assertSame($coach->id, $own->fresh()->user_id);
    }
}
