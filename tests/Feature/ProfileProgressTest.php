<?php

namespace Tests\Feature;

use App\Models\BodyWeightEntry;
use App\Models\ScheduleEntry;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\BodyWeightHistory;
use App\Services\PlanRecommendations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProfileProgressTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 10, 27)->setTime(12, 0));
        Storage::fake('public');
        $this->user = User::factory()->create();
    }

    private function preferences(array $overrides = []): array
    {
        return [...['training_goal' => 'build_muscle', 'experience_level' => 'intermediate', 'training_days_per_week' => 4, 'session_duration_minutes' => 60, 'equipment_access' => ['full_gym'], 'body_weight_goal' => 'maintain'], ...$overrides];
    }

    private function record(float $weight, string $date): BodyWeightEntry
    {
        $this->actingAs($this->user)->post('/body-weight', ['weight_kg' => $weight, 'recorded_date' => $date])->assertSessionHasNoErrors();

        return $this->user->bodyWeightEntries()->latest('id')->firstOrFail();
    }

    private function workoutSession(array $attributes = []): ScheduleEntry
    {
        $plan = $this->user->trainingPlans()->create(['name' => 'Preserved plan']);
        $day = $plan->trainingDays()->create(['title' => 'Strength', 'type' => 'training']);
        $day->exercises()->create(['name' => 'Squat', 'sets' => 3, 'reps' => '8', 'target_weight' => 80]);

        return $this->user->scheduleEntries()->create([...['training_day_id' => $day->id, 'title' => 'Strength', 'entry_type' => 'training', 'scheduled_date' => '2026-10-26', 'status' => 'planned'], ...$attributes]);
    }

    public function test_new_pages_require_authentication(): void
    {
        foreach (['/profile', '/profile/edit', '/onboarding', '/progress', '/recommendations'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->post('/body-weight', [])->assertRedirect('/login');
        $this->put('/profile', [])->assertRedirect('/login');
    }

    public function test_profile_is_owned_and_calculates_age_and_bmi_without_storing_them(): void
    {
        $other = User::factory()->create();
        $other->profile()->create(['height_cm' => 160, 'current_weight_kg' => 60]);
        $this->actingAs($this->user)->put('/profile', [...$this->preferences(), 'user_id' => $other->id, 'name' => 'Ahmad', 'date_of_birth' => '1990-05-15', 'height_cm' => 178, 'current_weight_kg' => 82])->assertSessionHasNoErrors()->assertRedirect('/profile');
        $this->get('/profile')->assertInertia(fn (Assert $page) => $page->component('Profile/Show')->where('profile.user_id', $this->user->id)->where('profile.age', 36)->where('profile.bmi', 25.9));
        $this->assertSame('60.00', $other->profile->current_weight_kg);
        $this->assertSame('Ahmad', $this->user->fresh()->name);
        $this->assertFalse(Schema::hasColumn('user_profiles', 'age'));
        $this->assertFalse(Schema::hasColumn('user_profiles', 'bmi'));
        $this->assertDatabaseCount('body_weight_entries', 1);
        $this->get('/profile/'.$other->id)->assertNotFound();
        $this->travelTo(now()->setDate(2027, 5, 15));
        $this->assertSame(37, $this->user->fresh()->profile->age);
    }

    public function test_profile_optional_fields_and_invalid_values_are_safe(): void
    {
        $this->actingAs($this->user)->put('/profile', [])->assertSessionHasNoErrors();
        $this->assertNull($this->user->fresh()->profile->bmi);
        $this->put('/profile', ['height_cm' => 178])->assertSessionHasNoErrors();
        $this->assertNull($this->user->fresh()->profile->bmi);
        $this->put('/profile', ['date_of_birth' => '2027-01-01', 'height_cm' => 0, 'current_weight_kg' => -1, 'training_days_per_week' => 8, 'equipment_access' => ['unknown'], 'training_goal' => 'bmi', 'session_duration_minutes' => 0])->assertSessionHasErrors(['date_of_birth', 'height_cm', 'current_weight_kg', 'training_days_per_week', 'equipment_access.0', 'training_goal', 'session_duration_minutes']);
        $this->assertSame('178.00', $this->user->fresh()->profile->height_cm);
    }

    public function test_training_goal_and_body_goal_are_independent_and_target_is_optional(): void
    {
        $this->actingAs($this->user)->put('/profile', $this->preferences(['training_goal' => 'get_stronger', 'body_weight_goal' => 'lose', 'target_body_weight_kg' => 76]))->assertSessionHasNoErrors();
        $profile = $this->user->fresh()->profile;
        $this->assertSame('get_stronger', $profile->training_goal);
        $this->assertSame('lose', $profile->body_weight_goal);
        $this->assertSame('76.00', $profile->target_body_weight_kg);
        $this->put('/profile', ['body_weight_goal' => 'maintain'])->assertSessionHasNoErrors();
        $this->assertNull($profile->fresh()->target_body_weight_kg);
        $this->put('/profile', ['body_weight_goal' => 'gain'])->assertSessionHasNoErrors();
        $this->assertNull($profile->fresh()->target_body_weight_kg);
        $this->put('/profile', ['body_weight_goal' => 'gain', 'target_body_weight_kg' => 85])->assertSessionHasNoErrors();
        $this->put('/profile', ['body_weight_goal' => null])->assertSessionHasNoErrors();
        $this->assertNull($profile->fresh()->target_body_weight_kg);
    }

    public function test_first_login_shows_skippable_onboarding_and_skip_is_persisted(): void
    {
        $this->post('/login', ['email' => $this->user->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->component('Onboarding/Index'));
        $this->post('/onboarding/skip')->assertRedirect('/dashboard');
        $this->assertNotNull($this->user->fresh()->profile->onboarding_skipped_at);
        $this->assertNull($this->user->fresh()->profile->onboarding_completed_at);
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->component('Dashboard'));
        $this->post('/logout');
        $this->post('/login', ['email' => $this->user->email, 'password' => 'password']);
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->component('Dashboard'));
        $this->get('/onboarding')->assertOk();
    }

    public function test_registration_stays_simple_and_completed_onboarding_does_not_reopen(): void
    {
        $this->post('/register', ['name' => 'New user', 'email' => 'new@example.com', 'password' => 'StrongPassword1!', 'password_confirmation' => 'StrongPassword1!'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->component('Onboarding/Index'));
        $this->post('/onboarding', $this->preferences())->assertSessionHasNoErrors()->assertRedirect('/recommendations');
        $new = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertNotNull($new->profile->onboarding_completed_at);
        $this->assertDatabaseCount('training_plans', 0);
        $this->post('/logout');
        $this->post('/login', ['email' => $new->email, 'password' => 'StrongPassword1!']);
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->component('Dashboard'));
    }

    public function test_onboarding_can_finish_with_every_optional_field_empty(): void
    {
        $this->actingAs($this->user)->post('/onboarding', [])->assertSessionHasNoErrors()->assertRedirect('/recommendations');
        $this->get('/recommendations')->assertInertia(fn (Assert $page) => $page->has('recommendations', 0));
        $this->assertNotNull($this->user->fresh()->profile->onboarding_completed_at);
    }

    public function test_weight_history_keeps_entries_and_backdated_values_do_not_replace_current(): void
    {
        $latest = $this->record(82, '2026-10-27');
        $old = $this->record(85, '2026-10-01');
        $this->assertSame('82.00', $this->user->fresh()->profile->current_weight_kg);
        $this->put('/body-weight/'.$old->id, ['weight_kg' => 84, 'recorded_date' => '2026-10-02', 'note' => 'Corrected'])->assertSessionHasNoErrors();
        $this->assertSame('82.00', $this->user->fresh()->profile->current_weight_kg);
        $this->put('/body-weight/'.$latest->id, ['weight_kg' => 81.5, 'recorded_date' => '2026-10-27'])->assertSessionHasNoErrors();
        $this->assertSame('81.50', $this->user->fresh()->profile->current_weight_kg);
        $this->assertDatabaseCount('body_weight_entries', 2);
        $this->delete('/body-weight/'.$latest->id)->assertSessionHasNoErrors();
        $this->assertSame('84.00', $this->user->fresh()->profile->current_weight_kg);
        $this->delete('/body-weight/'.$old->id)->assertSessionHasNoErrors();
        $this->assertNull($this->user->fresh()->profile->current_weight_kg);
    }

    public function test_same_day_measurements_use_the_latest_record_and_profile_edits_preserve_history(): void
    {
        $this->record(82, '2026-10-27');
        $this->record(81.5, '2026-10-27');
        $this->assertSame('81.50', $this->user->fresh()->profile->current_weight_kg);
        $this->put('/profile', ['current_weight_kg' => 81])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('body_weight_entries', 3);
        $this->put('/profile', ['current_weight_kg' => 81])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('body_weight_entries', 3);
        $this->put('/profile', ['current_weight_kg' => null])->assertSessionHasNoErrors();
        $this->assertSame('81.00', $this->user->fresh()->profile->current_weight_kg);
    }

    public function test_foreign_weight_entries_cannot_be_read_changed_or_deleted(): void
    {
        $other = User::factory()->create();
        $entry = $other->bodyWeightEntries()->create(['weight_kg' => 90, 'recorded_date' => '2026-10-27']);
        $this->actingAs($this->user)->put('/body-weight/'.$entry->id, ['weight_kg' => 80, 'recorded_date' => '2026-10-27'])->assertForbidden();
        $this->delete('/body-weight/'.$entry->id)->assertForbidden();
        $this->get('/progress')->assertInertia(fn (Assert $page) => $page->has('trend', 0)->has('entries.data', 0));
        $this->post('/body-weight', ['user_id' => $other->id, 'weight_kg' => 80, 'recorded_date' => '2026-10-27'])->assertSessionHasNoErrors();
        $this->assertSame(1, $other->bodyWeightEntries()->count());
        $this->assertSame(1, $this->user->bodyWeightEntries()->count());
    }

    public function test_body_weight_validation_does_not_mutate_history(): void
    {
        $this->actingAs($this->user)->post('/body-weight', ['weight_kg' => 0, 'recorded_date' => '2027-01-01'])->assertSessionHasErrors(['weight_kg', 'recorded_date']);
        $this->post('/body-weight', ['weight_kg' => '82.123', 'recorded_date' => '2026-10-27'])->assertSessionHasErrors('weight_kg');
        $this->assertDatabaseCount('body_weight_entries', 0);
    }

    public function test_progress_calculates_loss_gain_clamping_and_empty_values(): void
    {
        $this->actingAs($this->user)->get('/progress')->assertInertia(fn (Assert $page) => $page->where('summary.current', null)->where('summary.starting', null)->where('summary.change', null)->where('summary.progress', null));
        $profile = $this->user->profile()->create(['body_weight_goal' => 'lose', 'target_body_weight_kg' => 76]);
        $this->record(88, '2026-10-01');
        $this->record(82, '2026-10-27');
        $this->get('/progress')->assertInertia(fn (Assert $page) => $page->where('summary.current', 82)->where('summary.starting', 88)->where('summary.change', -6)->where('summary.remaining', 6)->where('summary.progress', 50));
        $this->record(74, '2026-10-27');
        $this->assertEquals(100, app(BodyWeightHistory::class)->summary($this->user->fresh())['progress']);
        $profile->update(['body_weight_goal' => 'gain', 'target_body_weight_kg' => 100]);
        $this->assertEquals(0, app(BodyWeightHistory::class)->summary($this->user->fresh())['progress']);
        $this->record(94, '2026-10-27');
        $this->assertEquals(50, app(BodyWeightHistory::class)->summary($this->user->fresh())['progress']);
        $profile->update(['target_body_weight_kg' => 88]);
        $this->assertNull(app(BodyWeightHistory::class)->summary($this->user->fresh())['progress']);
        $profile->update(['body_weight_goal' => 'not_tracking', 'target_body_weight_kg' => null]);
        $this->assertNull(app(BodyWeightHistory::class)->summary($this->user->fresh())['progress']);
    }

    public function test_training_progress_counts_due_workouts_only(): void
    {
        $this->workoutSession(['status' => 'completed']);
        $this->workoutSession(['status' => 'skipped']);
        $this->workoutSession();
        $this->workoutSession(['status' => 'completed', 'entry_type' => 'rest']);
        $this->workoutSession(['status' => 'completed', 'scheduled_date' => '2026-10-28']);
        $this->workoutSession(['status' => 'completed', 'scheduled_date' => '2026-10-27', 'scheduled_time' => '20:00']);
        $this->workoutSession(['status' => 'completed', 'scheduled_date' => '2026-09-27']);
        $this->actingAs($this->user)->get('/progress')->assertInertia(fn (Assert $page) => $page->where('training.completed', 1)->where('training.skipped', 1)->where('training.due', 3)->where('training.completion_rate', 33));
    }

    public function test_weekly_check_in_is_optional_and_dismissal_survives_reload(): void
    {
        $profile = $this->user->profile()->create(['body_weight_goal' => 'maintain']);
        $this->record(82, '2026-10-20');
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('checkIn.last_date', '2026-10-20'));
        $this->post('/body-weight-check-in/dismiss');
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('checkIn', null));
        $this->travelTo(now()->addDays(7));
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('checkIn.last_date', '2026-10-20'));
        $this->record(81, today()->toDateString());
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('checkIn', null));
        $profile->update(['body_weight_goal' => 'not_tracking']);
        $this->travelTo(now()->addDays(8));
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('checkIn', null));
    }

    public function test_recommendations_use_goal_experience_and_days_and_ignore_bmi(): void
    {
        $profile = $this->user->profile()->create($this->preferences());
        $service = app(PlanRecommendations::class);
        $this->assertSame('upper-lower', $service->forProfile($profile)[0]['key']);
        $profile->update(['training_days_per_week' => 5]);
        $this->assertSame('push-pull-legs', $service->forProfile($profile)[0]['key']);
        $split = $service->forProfile($profile)[0];
        $this->assertSame('Push · A', $split['days'][0]['title']);
        $this->assertSame('Pull · B', $split['days'][1]['title']);
        $this->assertContains('builtin:Triceps_Pushdown', array_column($split['days'][0]['exercises'], 'key'));
        $this->assertContains('builtin:Wide-Grip_Lat_Pulldown', array_column($split['days'][1]['exercises'], 'key'));
        $profile->update(['experience_level' => 'beginner', 'training_days_per_week' => 3]);
        $this->assertSame('full-body', $service->forProfile($profile)[0]['key']);
        $profile->update(['training_goal' => 'endurance']);
        $this->assertSame('endurance', $service->forProfile($profile)[0]['key']);
        $profile->update(['training_goal' => 'mobility']);
        $before = $service->forProfile($profile);
        $this->assertSame('mobility', $before[0]['key']);
        $profile->update(['height_cm' => 150, 'current_weight_kg' => 150]);
        $this->assertSame($before, $service->forProfile($profile));
        $profile->update(['training_goal' => 'lose_fat']);
        $this->assertSame('mixed-fitness', $service->forProfile($profile)[0]['key']);
    }

    public function test_recommendations_adapt_equipment_and_duration_and_support_all_presets(): void
    {
        $service = app(PlanRecommendations::class);
        $profile = $this->user->profile()->create($this->preferences(['equipment_access' => ['bodyweight']]));
        $plans = $service->forProfile($profile);
        $this->assertNotContains('upper-lower', array_column($plans, 'key'));
        $this->assertSame('builtin:Bodyweight_Squat', $plans[0]['days'][0]['exercises'][0]['key']);
        $profile->update(['equipment_access' => ['dumbbells']]);
        $plans = $service->forProfile($profile);
        $this->assertSame('upper-lower', $plans[0]['key']);
        $this->assertSame('builtin:Dumbbell_Bench_Press', $plans[0]['days'][0]['exercises'][0]['key']);
        $profile->update(['session_duration_minutes' => 30]);
        $this->assertCount(3, $service->forProfile($profile)[0]['days'][0]['exercises']);
        $profile->update(['equipment_access' => ['full_gym'], 'session_duration_minutes' => 90, 'training_days_per_week' => 7, 'experience_level' => 'advanced']);
        foreach (array_keys(UserProfile::GOALS) as $goal) {
            $profile->update(['training_goal' => $goal]);
            foreach ($service->forProfile($profile) as $plan) {
                foreach ($plan['days'] as $day) {
                    foreach ($day['exercises'] as $exercise) {
                        $this->assertFileExists(public_path('exercise-library/'.$exercise['image']));
                    }
                }
            }
        }
    }

    public function test_recommendations_are_only_copied_on_explicit_selection_and_do_not_schedule(): void
    {
        $this->user->profile()->create($this->preferences());
        $this->actingAs($this->user)->get('/recommendations')->assertInertia(fn (Assert $page) => $page->has('recommendations', 3));
        $this->assertDatabaseCount('training_plans', 0);
        $this->post('/recommendations', ['preset' => 'upper-lower'])->assertSessionHasNoErrors();
        $plan = $this->user->trainingPlans()->firstOrFail();
        $this->assertSame(4, $plan->trainingDays()->count());
        $exercise = $plan->trainingDays->first()->exercises->first();
        $this->assertNull($exercise->target_weight);
        Storage::disk('public')->assertExists($exercise->thumbnail_path);
        $this->assertDatabaseCount('schedule_entries', 0);
        $this->post('/recommendations', ['preset' => 'invalid'])->assertSessionHasErrors('preset');
        $this->assertDatabaseCount('training_plans', 1);
        $other = User::factory()->create();
        $this->actingAs($other)->post('/recommendations', ['preset' => 'upper-lower'])->assertSessionHasErrors('preset');
        $this->get('/training-plans/'.$plan->id)->assertForbidden();
    }

    public function test_completion_remains_one_click_and_optional_details_preserve_snapshot(): void
    {
        $entry = $this->workoutSession();
        $this->actingAs($this->user)->patch('/schedule-entries/'.$entry->id.'/status', ['status' => 'completed'])->assertSessionHasNoErrors();
        $entry->refresh();
        $snapshot = $entry->workout_snapshot;
        $completedAt = $entry->completed_at->toISOString();
        $this->assertNull($entry->duration_minutes);
        $this->assertNull($entry->calories_burned);
        $this->assertEquals(80, $snapshot['exercises'][0]['target_weight']);
        $this->from('/training-history/'.$entry->id)->patch('/schedule-entries/'.$entry->id, ['details_only' => true, 'duration_minutes' => 58, 'calories_burned' => 410, 'calories_source' => 'manual'])->assertSessionHasNoErrors()->assertRedirect('/training-history/'.$entry->id);
        $entry->refresh();
        $this->assertSame($snapshot, $entry->workout_snapshot);
        $this->assertSame($completedAt, $entry->completed_at->toISOString());
        $this->assertSame('manual', $entry->calories_source);
        $this->get('/training-history')->assertInertia(fn (Assert $page) => $page->where('entries.data.0.duration_minutes', 58)->where('entries.data.0.calories_burned', 410));
        $this->get('/training-history/'.$entry->id)->assertInertia(fn (Assert $page) => $page->where('entry.duration_minutes', 58)->where('entry.calories_source', 'manual')->where('workout.version', 1));
        $this->patch('/schedule-entries/'.$entry->id, ['details_only' => true, 'duration_minutes' => null, 'calories_burned' => 0])->assertSessionHasNoErrors();
        $this->assertEquals(0, $entry->fresh()->calories_burned);
        $this->patch('/schedule-entries/'.$entry->id, ['details_only' => true, 'calories_burned' => null])->assertSessionHasNoErrors();
        $this->assertNull($entry->fresh()->calories_source);
    }

    public function test_optional_details_validate_status_ownership_and_preserve_legacy_records(): void
    {
        $entry = $this->workoutSession();
        $this->actingAs($this->user)->patch('/schedule-entries/'.$entry->id, ['details_only' => true, 'duration_minutes' => 60])->assertSessionHasErrors('details');
        $entry->update(['status' => 'completed']);
        $this->patch('/schedule-entries/'.$entry->id, ['details_only' => true, 'duration_minutes' => -1, 'calories_burned' => -1])->assertSessionHasErrors(['duration_minutes', 'calories_burned']);
        $this->patch('/schedule-entries/'.$entry->id, ['details_only' => true, 'duration_minutes' => 40])->assertSessionHasNoErrors();
        $this->assertNull($entry->fresh()->workout_snapshot);
        $this->get('/training-history/'.$entry->id)->assertInertia(fn (Assert $page) => $page->where('workout', null)->where('entry.duration_minutes', 40));
        $other = User::factory()->create();
        $this->actingAs($other)->patch('/schedule-entries/'.$entry->id, ['details_only' => true, 'duration_minutes' => 60])->assertForbidden();
        $rest = $this->workoutSession(['entry_type' => 'rest', 'status' => 'completed']);
        $this->actingAs($this->user)->patch('/schedule-entries/'.$rest->id, ['details_only' => true, 'calories_burned' => 100])->assertSessionHasErrors('details');
    }

    public function test_status_reversal_keeps_details_for_recompletion_but_hides_them_in_history(): void
    {
        $entry = $this->workoutSession();
        $this->actingAs($this->user)->patch('/schedule-entries/'.$entry->id.'/status', ['status' => 'completed']);
        $this->patch('/schedule-entries/'.$entry->id, ['details_only' => true, 'duration_minutes' => 58, 'calories_burned' => 410]);
        $this->patch('/schedule-entries/'.$entry->id.'/status', ['status' => 'skipped']);
        $this->assertEquals(58, $entry->fresh()->duration_minutes);
        $this->get('/training-history')->assertInertia(fn (Assert $page) => $page->where('entries.data.0.duration_minutes', null)->where('entries.data.0.calories_burned', null));
        $this->patch('/schedule-entries/'.$entry->id.'/status', ['status' => 'completed']);
        $this->get('/training-history')->assertInertia(fn (Assert $page) => $page->where('entries.data.0.duration_minutes', 58)->where('entries.data.0.calories_burned', 410));
    }
}
