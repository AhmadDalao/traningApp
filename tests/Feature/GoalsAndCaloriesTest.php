<?php

namespace Tests\Feature;

use App\Models\ScheduleEntry;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\PlanRecommendations;
use App\Services\WorkoutCalorieEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoalsAndCaloriesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');
        $this->travelTo(now()->setDate(2026, 10, 27)->setTime(12, 0));
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function profile(array $attributes = []): UserProfile
    {
        return $this->user->profile()->create([...[
            'training_goal' => 'build_muscle', 'experience_level' => 'intermediate',
            'training_days_per_week' => 4, 'session_duration_minutes' => 45,
            'equipment_access' => ['full_gym'], 'current_weight_kg' => 80,
        ], ...$attributes]);
    }

    private function workoutSession(array $attributes = []): ScheduleEntry
    {
        $plan = $this->user->trainingPlans()->create(['name' => 'Existing plan']);
        $day = $plan->trainingDays()->create(['title' => 'Leg Day', 'type' => 'training', 'focus_area' => 'Legs']);
        $day->exercises()->create(['name' => 'Squat', 'sets' => 3, 'reps' => '8', 'target_weight' => 50]);

        return $this->user->scheduleEntries()->create([...[
            'training_day_id' => $day->id, 'title' => 'Leg Day', 'entry_type' => 'training',
            'focus_area' => 'Legs', 'scheduled_date' => '2026-10-26', 'status' => 'planned',
        ], ...$attributes]);
    }

    private function complete(ScheduleEntry $entry): void
    {
        $this->patch('/schedule-entries/'.$entry->id.'/status', ['status' => 'completed'])->assertSessionHasNoErrors();
        $entry->refresh();
    }

    private function details(ScheduleEntry $entry, array $data): void
    {
        $this->patch('/schedule-entries/'.$entry->id, ['details_only' => true, ...$data])->assertSessionHasNoErrors();
        $entry->refresh();
    }

    public function test_legacy_goal_reads_without_backfill_or_reopening_onboarding(): void
    {
        $profile = $this->profile(['onboarding_completed_at' => now()]);
        foreach (['/profile', '/profile/edit', '/onboarding'] as $url) {
            $this->get($url)->assertInertia(fn (Assert $page) => $page->where('profile.training_goals', ['build_muscle']));
        }
        $this->assertNull($profile->fresh()->getRawOriginal('training_goals'));
        $this->assertFalse($profile->needsOnboarding());
        $this->get('/recommendations')->assertInertia(fn (Assert $page) => $page->where('recommendations.0.key', 'upper-lower'));
    }

    public static function goalSaves(): array
    {
        return [
            'profile one' => ['put', '/profile', ['build_muscle']],
            'profile two' => ['put', '/profile', ['lose_fat', 'build_muscle']],
            'profile three' => ['put', '/profile', ['build_muscle', 'get_stronger', 'mobility']],
            'onboarding three' => ['post', '/onboarding', ['lose_fat', 'endurance', 'general_fitness']],
            'returning three' => ['put', '/training-preferences', ['get_stronger', 'build_muscle', 'mobility']],
            'onboarding one' => ['post', '/onboarding', ['endurance']],
            'onboarding two' => ['post', '/onboarding', ['build_muscle', 'get_stronger']],
        ];
    }

    #[DataProvider('goalSaves')]
    public function test_one_two_or_three_ordered_goals_save_in_profile_and_onboarding(string $method, string $url, array $goals): void
    {
        $this->$method($url, ['training_goals' => $goals, 'training_goal' => 'mobility'])->assertSessionHasNoErrors();
        $profile = $this->user->fresh()->profile;
        $this->assertSame($goals, $profile->training_goals);
        $this->assertSame($goals[0], $profile->training_goal);
        $this->assertSame($goals, json_decode($profile->getRawOriginal('training_goals'), true));
        $this->get('/profile')->assertInertia(fn (Assert $page) => $page->where('profile.training_goals', $goals));
        if ($url === '/onboarding') {
            $this->assertNotNull($profile->onboarding_completed_at);
        }
    }

    public function test_goal_validation_rejects_fourth_duplicate_unknown_equipment_and_non_list_values(): void
    {
        $profile = $this->profile();
        foreach ([['build_muscle', 'lose_fat', 'endurance', 'mobility'], ['build_muscle', 'build_muscle'], ['bodyweight'], ['unknown'], [''], ['primary' => 'build_muscle']] as $goals) {
            $this->put('/profile', ['training_goals' => $goals])->assertSessionHasErrors();
            $this->assertNull($profile->fresh()->getRawOriginal('training_goals'));
        }
    }

    public function test_goal_priority_clear_and_legacy_writes_preserve_independent_preferences(): void
    {
        $profile = $this->profile(['body_weight_goal' => 'lose', 'target_body_weight_kg' => 76, 'equipment_access' => ['bodyweight']]);
        $this->put('/profile', ['training_goals' => ['build_muscle', 'lose_fat']])->assertSessionHasNoErrors();
        $this->put('/profile', ['training_goals' => ['lose_fat', 'build_muscle']])->assertSessionHasNoErrors();
        $this->assertSame('lose_fat', $profile->fresh()->training_goal);
        $this->assertSame('lose', $profile->fresh()->body_weight_goal);
        $this->assertSame('76.00', $profile->fresh()->target_body_weight_kg);
        $this->assertSame(['bodyweight'], $profile->fresh()->equipment_access);
        $this->put('/profile', ['training_goals' => []])->assertSessionHasNoErrors();
        $this->assertSame([], $profile->fresh()->training_goals);
        $this->assertNull($profile->fresh()->training_goal);
        $this->put('/profile', ['training_goal' => 'endurance'])->assertSessionHasNoErrors();
        $this->assertSame(['endurance'], $profile->fresh()->training_goals);
    }

    public function test_ordered_goals_take_priority_over_legacy_goal_and_bmi_never_changes_recommendations(): void
    {
        $profile = $this->profile(['training_goals' => ['mobility', 'general_fitness']]);
        $service = app(PlanRecommendations::class);
        $before = $service->forProfile($profile);
        $this->assertSame('mobility', $before[0]['key']);
        $profile->update(['height_cm' => 150, 'current_weight_kg' => 150, 'body_weight_goal' => 'gain']);
        $this->assertSame($before, $service->forProfile($profile));
        $this->assertContains('Mobility', array_column($before[0]['days'], 'focus_area'));
    }

    public function test_ordered_goals_rank_curated_plans_without_mutating_their_prescriptions(): void
    {
        $profile = $this->profile(['training_goals' => ['build_muscle'], 'session_duration_minutes' => 60]);
        $service = app(PlanRecommendations::class);
        $catalog = $service->catalog();
        $muscle = $service->forProfile($profile)[0];
        $this->assertSame('upper-lower', $muscle['key']);
        $profile->update(['training_goals' => ['lose_fat', 'build_muscle']]);
        $fatLoss = $service->forProfile($profile)[0];
        $this->assertSame('mixed-fitness', $fatLoss['key']);
        $this->assertContains('Cardio', array_column($fatLoss['days'], 'focus_area'));
        $profile->update(['training_goals' => ['get_stronger', 'build_muscle']]);
        $strength = $service->forProfile($profile)[0];
        $this->assertSame('strength', $strength['key']);
        $this->assertSame('4–6', $strength['days'][0]['exercises'][0]['reps']);
        $this->assertSame($catalog, $service->catalog());
    }

    public function test_bodyweight_combination_reuses_four_day_preset_and_copies_without_scheduling(): void
    {
        $this->profile(['training_goals' => ['build_muscle', 'lose_fat'], 'equipment_access' => ['bodyweight']]);
        $this->get('/recommendations')->assertInertia(fn (Assert $page) => $page
            ->where('recommendations.0.key', 'bodyweight-strength')->where('recommendations.0.days_per_week', 4)
            ->where('recommendations.0.reasons.0', 'Primary goal: Build muscle')
            ->where('recommendations.0.reasons.1', 'Secondary goal: Lose fat')
            ->where('recommendations.0.equipment', 'bodyweight'));
        $this->get('/recommendations/bodyweight-strength')->assertInertia(fn (Assert $page) => $page->where('plan.days.3.focus_area', 'Cardio'));
        $this->assertDatabaseCount('training_plans', 0);
        $this->post('/recommendations', ['preset' => 'bodyweight-strength'])->assertSessionHasNoErrors();
        $plan = $this->user->trainingPlans()->firstOrFail();
        $this->assertSame(4, $plan->trainingDays()->count());
        foreach ($plan->trainingDays as $day) {
            $this->assertSame(4, $day->exercises()->count());
            foreach ($day->exercises as $exercise) {
                $this->assertNull($exercise->target_weight);
                Storage::disk('public')->assertExists($exercise->thumbnail_path);
            }
        }
        $this->assertDatabaseCount('schedule_entries', 0);
    }

    public function test_common_goal_pairs_rank_bodyweight_first_and_have_valid_library_exercises(): void
    {
        $profile = $this->profile(['equipment_access' => ['bodyweight']]);
        $service = app(PlanRecommendations::class);
        foreach ([['build_muscle', 'lose_fat'], ['build_muscle', 'get_stronger'], ['get_stronger', 'build_muscle'], ['lose_fat', 'endurance'], ['general_fitness', 'lose_fat'], ['mobility', 'general_fitness'], ['endurance', 'general_fitness']] as $goals) {
            $profile->update(['training_goals' => $goals]);
            $plans = $service->forProfile($profile);
            $this->assertSame('bodyweight', $plans[0]['equipment']);
            foreach ($plans as $plan) {
                foreach ($plan['reasons'] as $reason) {
                    if (str_starts_with($reason, 'Primary goal: ')) {
                        $this->assertContains($goals[0], $plan['goals']);
                    }
                }
                foreach ($plan['days'] as $day) {
                    foreach ($day['exercises'] as $exercise) {
                        if ($plan['equipment'] === 'bodyweight') {
                            $this->assertContains($exercise['equipment'], [null, 'body only']);
                        }
                        $this->assertNotEmpty($exercise['key']);
                    }
                }
            }
        }
    }

    public static function metValues(): array
    {
        return [
            ['strength', 'light', 210], ['strength', 'moderate', 245], ['strength', 'vigorous', 420],
            ['cardio', 'light', 280], ['cardio', 'moderate', 420], ['cardio', 'vigorous', 560],
            ['mobility', 'light', 140], ['mobility', 'moderate', 175], ['mobility', 'vigorous', 210],
        ];
    }

    #[DataProvider('metValues')]
    public function test_met_formula_rounds_each_category_and_intensity(string $category, string $intensity, int $expected): void
    {
        $this->assertSame($expected, app(WorkoutCalorieEstimator::class)->estimate(80, 50, $category, $intensity));
    }

    public function test_estimator_handles_invalid_or_missing_inputs(): void
    {
        $estimator = app(WorkoutCalorieEstimator::class);
        foreach ([[null, 50, 'strength', 'moderate'], [80, null, 'strength', 'moderate'], [0, 50, 'strength', 'moderate'], [80, 0, 'strength', 'moderate'], [80, 50, 'unknown', 'moderate'], [80, 50, 'strength', 'unknown']] as $args) {
            $this->assertNull($estimator->estimate(...$args));
        }
        $this->assertSame(214, $estimator->estimate(70, 50, 'strength'));
    }

    public function test_one_click_completion_stays_optional_and_details_generate_server_estimate(): void
    {
        $this->profile();
        $entry = $this->workoutSession();
        $this->complete($entry);
        $snapshot = $entry->getRawOriginal('workout_snapshot');
        $completedAt = $entry->completed_at->toISOString();
        $this->assertNull($entry->estimated_calories);
        $this->details($entry, ['duration_minutes' => 50, 'estimated_calories' => 99999]);
        $this->assertEquals(245, $entry->estimated_calories);
        $this->assertSame('moderate', $entry->intensity);
        $this->assertNull($entry->calories_burned);
        $this->assertSame($snapshot, $entry->getRawOriginal('workout_snapshot'));
        $this->assertSame($completedAt, $entry->completed_at->toISOString());
    }

    public function test_completion_with_existing_duration_uses_default_intensity_and_profile_fallback(): void
    {
        $this->profile();
        $entry = $this->workoutSession(['duration_minutes' => 50]);
        $this->complete($entry);
        $this->assertEquals(245, $entry->estimated_calories);
        $this->assertSame('moderate', $entry->intensity);
    }

    public function test_missing_weight_or_duration_and_ineligible_sessions_have_no_estimate(): void
    {
        $entry = $this->workoutSession();
        $this->complete($entry);
        $this->details($entry, ['duration_minutes' => 50]);
        $this->assertNull($entry->estimated_calories);
        $this->profile();
        $this->details($entry, ['duration_minutes' => null]);
        $this->assertNull($entry->estimated_calories);
        foreach ([['entry_type' => 'rest', 'status' => 'completed'], ['status' => 'planned'], ['status' => 'skipped']] as $attributes) {
            $ineligible = $this->workoutSession([...$attributes, 'duration_minutes' => 50]);
            $this->assertNull(app(WorkoutCalorieEstimator::class)->forSession($ineligible));
            $this->patch('/schedule-entries/'.$ineligible->id, ['details_only' => true, 'duration_minutes' => 50])->assertSessionHasErrors('details');
        }
        $rest = $this->workoutSession(['entry_type' => 'rest', 'duration_minutes' => 50]);
        $this->complete($rest);
        $this->assertNull($rest->estimated_calories);
    }

    public function test_focus_mapping_is_based_on_saved_session_and_not_changed_template(): void
    {
        $this->profile();
        foreach (['Cardio' => 420, 'Mobility' => 175, 'Chest' => 245, 'Back' => 245, 'Shoulders' => 245, 'Arms' => 245, 'Legs' => 245, 'Core' => 245, 'Full Body' => 245, 'Other' => 245] as $focus => $expected) {
            $entry = $this->workoutSession(['focus_area' => $focus, 'duration_minutes' => 50]);
            $entry->trainingDay->update(['focus_area' => 'Mobility']);
            $this->complete($entry);
            $this->assertEquals($expected, $entry->estimated_calories);
        }
    }

    public function test_historical_weight_uses_latest_owned_entry_on_or_before_session_date(): void
    {
        $this->profile(['current_weight_kg' => 100]);
        foreach ([['2026-10-25', 70], ['2026-10-26', 75], ['2026-10-26', 80], ['2026-10-27', 90]] as [$date, $weight]) {
            $this->user->bodyWeightEntries()->create(['recorded_date' => $date, 'weight_kg' => $weight]);
        }
        User::factory()->create()->bodyWeightEntries()->create(['recorded_date' => '2026-10-26', 'weight_kg' => 120]);
        $entry = $this->workoutSession(['duration_minutes' => 50]);
        $this->complete($entry);
        $this->assertEquals(245, $entry->estimated_calories);
        $earlier = $this->workoutSession(['duration_minutes' => 50, 'scheduled_date' => '2026-10-20']);
        $this->complete($earlier);
        $this->assertEquals(306, $earlier->estimated_calories);
        $this->assertDatabaseCount('body_weight_entries', 5);
    }

    public function test_stored_estimate_survives_weight_changes_reads_reported_edits_and_repeated_completion(): void
    {
        $this->profile();
        $entry = $this->workoutSession(['duration_minutes' => 50]);
        $this->complete($entry);
        $this->put('/profile', ['current_weight_kg' => 100])->assertSessionHasNoErrors();
        $this->post('/body-weight', ['weight_kg' => 110, 'recorded_date' => '2026-10-25'])->assertSessionHasNoErrors();
        $this->get('/schedule-entries/'.$entry->id)->assertOk();
        $this->get('/training-history/'.$entry->id)->assertOk();
        $this->details($entry, ['calories_burned' => 340, 'calories_source' => 'wearable']);
        $this->complete($entry);
        $this->assertEquals(245, $entry->estimated_calories);
        $this->assertEquals(340, $entry->calories_burned);
    }

    public function test_duration_and_intensity_changes_recalculate_without_changing_reported_values(): void
    {
        $this->profile();
        $entry = $this->workoutSession();
        $this->complete($entry);
        $this->details($entry, ['duration_minutes' => 50, 'intensity' => 'moderate', 'calories_burned' => 340, 'calories_source' => 'wearable']);
        $this->details($entry, ['duration_minutes' => 60]);
        $this->assertEquals(294, $entry->estimated_calories);
        $this->details($entry, ['intensity' => 'vigorous']);
        $this->assertEquals(504, $entry->estimated_calories);
        $this->assertEquals(340, $entry->calories_burned);
        $this->assertSame('wearable', $entry->calories_source);
        $this->details($entry, ['intensity' => 'light']);
        $this->assertEquals(252, $entry->estimated_calories);
        $this->details($entry, ['duration_minutes' => null]);
        $this->assertNull($entry->estimated_calories);
        $this->assertEquals(340, $entry->calories_burned);
    }

    public function test_reported_sources_zero_and_clearing_keep_estimate_and_history_separate(): void
    {
        $this->profile();
        $entry = $this->workoutSession(['duration_minutes' => 50]);
        $this->complete($entry);
        foreach (['wearable', 'exercise_machine', 'manual'] as $source) {
            $this->details($entry, ['calories_burned' => 340, 'calories_source' => $source]);
            $this->assertSame($source, $entry->calories_source);
            $this->assertEquals(245, $entry->estimated_calories);
        }
        $this->get('/training-history')->assertInertia(fn (Assert $page) => $page->where('entries.data.0.calories_burned', 340)->where('entries.data.0.estimated_calories', 245));
        $this->details($entry, ['calories_burned' => 0]);
        $this->assertEquals(0, $entry->calories_burned);
        $this->assertEquals(245, $entry->estimated_calories);
        $this->details($entry, ['calories_burned' => null, 'calories_source' => 'wearable']);
        $this->assertNull($entry->calories_source);
        $this->assertNull($entry->calories_burned);
        $this->assertEquals(245, $entry->estimated_calories);
        $this->get('/training-history')->assertInertia(fn (Assert $page) => $page->where('entries.data.0.calories_burned', null)->where('entries.data.0.estimated_calories', 245));
    }

    public function test_old_manual_calories_and_snapshots_are_read_without_backfill(): void
    {
        $this->profile();
        $snapshot = ['version' => 1, 'title' => 'Old workout', 'exercises' => [['name' => 'Squat', 'sets' => 3, 'reps' => '8', 'actual_weight' => '50.00']]];
        $entry = $this->workoutSession(['status' => 'completed', 'duration_minutes' => 50, 'calories_burned' => 410, 'calories_source' => 'manual', 'workout_snapshot' => $snapshot]);
        $raw = $entry->refresh()->getRawOriginal('workout_snapshot');
        $this->get('/training-history/'.$entry->id)->assertInertia(fn (Assert $page) => $page->where('entry.calories_burned', 410)->where('entry.estimated_calories', null)->where('entry.intensity', null)->where('workout.exercises.0.actual_weight', '50.00'));
        $this->assertSame($raw, $entry->fresh()->getRawOriginal('workout_snapshot'));
        $this->assertNull($entry->fresh()->estimated_calories);
    }

    public function test_status_reversal_removes_estimate_and_recompletion_can_estimate_without_changing_snapshot(): void
    {
        $this->profile();
        $entry = $this->workoutSession(['duration_minutes' => 50, 'calories_burned' => 340, 'calories_source' => 'wearable']);
        $this->complete($entry);
        $snapshot = $entry->getRawOriginal('workout_snapshot');
        foreach (['planned', 'skipped'] as $status) {
            $this->patch('/schedule-entries/'.$entry->id.'/status', ['status' => $status])->assertSessionHasNoErrors();
            $this->assertNull($entry->fresh()->estimated_calories);
            $this->get('/training-history')->assertInertia(fn (Assert $page) => $page->where('entries.data.0.estimated_calories', null)->where('entries.data.0.calories_burned', null));
            $this->complete($entry);
            $this->assertEquals(245, $entry->estimated_calories);
            $this->assertEquals(340, $entry->calories_burned);
            $this->assertSame($snapshot, $entry->getRawOriginal('workout_snapshot'));
        }
    }

    public function test_calorie_validation_and_ownership_reject_invalid_requests_without_mutation(): void
    {
        $this->profile();
        $entry = $this->workoutSession(['duration_minutes' => 50]);
        $this->complete($entry);
        foreach (['intensity' => 'extreme', 'calories_source' => 'estimated', 'duration_minutes' => 0, 'calories_burned' => -1] as $field => $value) {
            $this->patch('/schedule-entries/'.$entry->id, ['details_only' => true, $field => $value])->assertSessionHasErrors($field);
        }
        $this->assertEquals(245, $entry->fresh()->estimated_calories);
        $other = User::factory()->create();
        $this->actingAs($other)->patch('/schedule-entries/'.$entry->id, ['details_only' => true, 'duration_minutes' => 60])->assertForbidden();
        $this->get('/training-history/'.$entry->id)->assertForbidden();
        $this->patch('/schedule-entries/'.$entry->id.'/status', ['status' => 'completed'])->assertForbidden();
        $this->assertEquals(245, $entry->fresh()->estimated_calories);
    }

    public function test_editing_legacy_session_to_rest_removes_estimate_but_preserves_reported_data(): void
    {
        $this->profile();
        $entry = $this->workoutSession(['status' => 'completed', 'duration_minutes' => 50, 'calories_burned' => 340, 'calories_source' => 'manual']);
        $this->details($entry, ['intensity' => 'moderate']);
        $this->assertEquals(245, $entry->estimated_calories);
        $this->patch('/schedule-entries/'.$entry->id, ['entry_type' => 'rest', 'training_day_id' => null, 'scheduled_date' => '2026-10-26'])->assertSessionHasNoErrors();
        $this->assertNull($entry->fresh()->estimated_calories);
        $this->assertEquals(340, $entry->fresh()->calories_burned);
    }

    public function test_notes_preserve_estimate_while_explicit_date_change_uses_that_dates_weight(): void
    {
        $this->profile();
        $this->user->bodyWeightEntries()->create(['weight_kg' => 70, 'recorded_date' => '2026-10-25']);
        $this->user->bodyWeightEntries()->create(['weight_kg' => 80, 'recorded_date' => '2026-10-26']);
        $entry = $this->workoutSession(['duration_minutes' => 50]);
        $this->complete($entry);
        $snapshot = $entry->getRawOriginal('workout_snapshot');
        $metadata = ['entry_type' => 'training', 'training_day_id' => $entry->training_day_id, 'scheduled_date' => '2026-10-26', 'notes' => 'Updated note'];
        $this->patch('/schedule-entries/'.$entry->id, $metadata)->assertSessionHasNoErrors();
        $this->assertEquals(245, $entry->fresh()->estimated_calories);
        $this->patch('/schedule-entries/'.$entry->id, [...$metadata, 'scheduled_date' => '2026-10-25'])->assertSessionHasNoErrors();
        $this->assertEquals(214, $entry->fresh()->estimated_calories);
        $this->assertSame($snapshot, $entry->fresh()->getRawOriginal('workout_snapshot'));
    }
}
