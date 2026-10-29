<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserProfile;
use App\Services\ExerciseLibrary;
use App\Services\ExerciseProgress;
use App\Services\PlanRecommendations;
use App\Services\PlanSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlanDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');
        $this->travelTo(now()->setDate(2026, 10, 29)->setTime(12, 0));
    }

    public function test_new_routes_require_login_and_unknown_catalog_keys_are_rejected(): void
    {
        foreach (['/recommendations', '/recommendations/ppl-6', '/training-preferences'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->put('/training-preferences', [])->assertRedirect('/login');
        $this->post('/recommendations', ['preset' => 'ppl-6'])->assertRedirect('/login');
        $this->actingAs(User::factory()->create());
        $this->get('/recommendations/deleted-plan')->assertNotFound();
        foreach (['disabled-template', 'custom:1', '', str_repeat('a', 51), ['ppl-6']] as $key) {
            $this->post('/recommendations', ['preset' => $key])->assertSessionHasErrors('preset');
        }
        $this->assertDatabaseCount('training_plans', 0);
    }

    public function test_catalog_has_distinct_routines_canonical_metadata_and_real_exercise_identities(): void
    {
        $catalog = app(PlanRecommendations::class)->catalog();
        $this->assertCount(19, $catalog);
        $this->assertCount(19, array_unique(array_column($catalog, 'key')));
        $this->assertCount(19, array_unique(array_map(fn ($plan) => json_encode($plan['days']), $catalog)));
        $this->assertSame(['beginner' => 5, 'intermediate' => 8, 'advanced' => 6], array_count_values(array_column($catalog, 'level')));
        $library = app(ExerciseLibrary::class);
        foreach ($catalog as $plan) {
            $this->assertContains($plan['level'], array_keys(UserProfile::LEVELS));
            $this->assertContains($plan['equipment'], array_keys(UserProfile::EQUIPMENT));
            $this->assertContains($plan['primary_focus'], $plan['goals']);
            $this->assertSame($plan['days_per_week'], count($plan['days']));
            $this->assertSame($plan['split'], array_column($plan['days'], 'title'));
            foreach ($plan['days'] as $day) {
                $this->assertNotEmpty($day['notes']);
                foreach ($day['exercises'] as $exercise) {
                    $builtin = $library->find($exercise['key'], 0);
                    $this->assertSame($builtin['name'], $exercise['name']);
                    $this->assertSame($builtin['icon'], $exercise['icon']);
                    $this->assertGreaterThan(0, $exercise['sets']);
                    $this->assertNotEmpty($exercise['reps']);
                    $this->assertContains($exercise['tracking_mode'], ['reps', 'duration']);
                    $this->assertNull($exercise['target_weight']);
                    if ($plan['equipment'] === 'bodyweight') {
                        $this->assertContains($builtin['equipment'], ['body only', null]);
                    } elseif ($plan['equipment'] === 'dumbbells') {
                        $this->assertContains($builtin['equipment'], ['dumbbell', 'body only', null]);
                    }
                    $this->assertFileExists(public_path('exercise-library/'.$exercise['image']));
                }
            }
        }
    }

    public function test_sparse_profiles_get_useful_fallbacks_and_reads_do_not_write(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/recommendations')->assertInertia(fn (Assert $page) => $page->has('plans', 19)->has('recommendations', 3)->where('hasPreferences', false));
        $this->get('/recommendations/bodyweight-foundations')->assertInertia(fn (Assert $page) => $page->has('plan.days', 3)->has('plan.days.0.exercises', 4));
        $this->assertDatabaseCount('user_profiles', 0);
        $this->assertDatabaseCount('training_plans', 0);
        foreach (app(PlanRecommendations::class)->forProfile(null) as $plan) {
            $this->assertSame('beginner', $plan['level']);
        }
    }

    public function test_priority_is_weighted_in_order_and_reasons_only_claim_actual_matches(): void
    {
        $service = app(PlanRecommendations::class);
        $plan = collect($service->catalog())->firstWhere('key', 'hypertrophy');
        $scores = [];
        foreach ([['build_muscle', 'endurance', 'mobility'], ['endurance', 'build_muscle', 'mobility'], ['endurance', 'mobility', 'build_muscle']] as $goals) {
            $match = $service->match($plan, new UserProfile(['training_goals' => $goals]));
            $scores[] = $match['score'];
            $this->assertCount(1, $match['reasons']);
            $this->assertStringContainsString('Build muscle', $match['reasons'][0]);
        }
        $this->assertGreaterThan($scores[1], $scores[0]);
        $this->assertGreaterThan($scores[2], $scores[1]);
        $this->assertSame($scores[0], $service->match($plan, new UserProfile(['training_goal' => 'build_muscle']))['score']);
    }

    public function test_beginner_and_bodyweight_preferences_rank_suitable_plans_without_hiding_advanced(): void
    {
        $service = app(PlanRecommendations::class);
        $profile = new UserProfile(['training_goals' => ['build_muscle', 'get_stronger', 'general_fitness'], 'experience_level' => 'beginner', 'training_days_per_week' => 3, 'session_duration_minutes' => 30, 'equipment_access' => ['bodyweight']]);
        $ranked = $service->forProfile($profile);
        $this->assertSame('bodyweight-foundations', $ranked[0]['key']);
        $this->assertNotContains('ppl-6', array_column($ranked, 'key'));
        $profile->equipment_access = ['home_gym'];
        $this->assertSame('bodyweight', $service->forProfile($profile)[0]['equipment']);
        $profile->equipment_access = ['dumbbells'];
        $profile->session_duration_minutes = 45;
        $this->assertSame('full-body', $service->forProfile($profile)[0]['key']);
        $user = User::factory()->create();
        $user->profile()->create($profile->toArray());
        $this->actingAs($user)->get('/recommendations/ppl-6')->assertInertia(fn (Assert $page) => $page->where('plan.level', 'advanced')->has('plan.days', 6));
        $this->post('/recommendations', ['preset' => 'ppl-6'])->assertSessionHasNoErrors();
        $this->assertSame(6, $user->trainingPlans()->first()->trainingDays()->count());
    }

    public static function preferenceStates(): array
    {
        return [['completed'], ['skipped']];
    }

    #[DataProvider('preferenceStates')]
    public function test_returning_flow_prefills_and_preserves_onboarding_and_weight_history(string $state): void
    {
        $user = User::factory()->create();
        $profile = $user->profile()->create(['date_of_birth' => '1990-05-15', 'height_cm' => 178, 'current_weight_kg' => 82, 'training_goal' => 'build_muscle', 'training_goals' => ['build_muscle', 'get_stronger'], 'experience_level' => 'intermediate', 'training_days_per_week' => 4, 'session_duration_minutes' => 60, 'equipment_access' => ['full_gym'], 'body_weight_goal' => 'lose', 'target_body_weight_kg' => 76, 'onboarding_'.$state.'_at' => now()->subMonth()]);
        $user->bodyWeightEntries()->create(['weight_kg' => 82, 'recorded_date' => today()->subWeek()->toDateString()]);
        $before = $profile->fresh()->getAttributes();
        $this->actingAs($user)->get('/training-preferences')->assertInertia(fn (Assert $page) => $page->component('Onboarding/Index')->where('editing', true)->where('profile.date_of_birth', '1990-05-15')->where('profile.current_weight_kg', '82.00')->where('profile.training_goals', ['build_muscle', 'get_stronger'])->where('profile.equipment_access', ['full_gym']));
        $this->get('/profile')->assertOk(); // Cancelling is navigation, with no write.
        $this->assertSame($before, $profile->fresh()->getAttributes());
        $this->put('/training-preferences', ['training_goals' => ['endurance', 'lose_fat', 'mobility'], 'current_weight_kg' => 82, 'onboarding_completed_at' => now(), 'onboarding_skipped_at' => null, 'account_type' => 'coach'])->assertSessionHasNoErrors()->assertRedirect('/recommendations');
        $this->assertSame('endurance', $profile->fresh()->training_goal);
        foreach (['onboarding_completed_at', 'onboarding_skipped_at', 'date_of_birth', 'height_cm', 'equipment_access', 'body_weight_goal', 'target_body_weight_kg'] as $key) {
            $this->assertSame($before[$key] ?? null, $profile->fresh()->getRawOriginal($key));
        }
        $this->assertFalse($user->fresh()->isCoach());
        $this->assertDatabaseCount('body_weight_entries', 1);
        $this->assertDatabaseCount('training_plans', 0);
        $this->put('/training-preferences', ['current_weight_kg' => 81.5])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('body_weight_entries', 2);
    }

    public function test_three_goal_validation_on_returning_flow_is_atomic_and_owner_scoped(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $profile = $other->profile()->create(['training_goal' => 'endurance']);
        $before = $profile->fresh()->getAttributes();
        $this->actingAs($user)->put('/training-preferences', ['user_id' => $other->id, 'training_goals' => ['build_muscle', 'get_stronger', 'mobility']])->assertSessionHasNoErrors();
        $this->assertSame($before, $profile->fresh()->getAttributes());
        $ownBefore = $user->fresh()->profile->getAttributes();
        foreach ([['build_muscle', 'get_stronger', 'mobility', 'endurance'], ['mobility', 'mobility'], ['unknown']] as $goals) {
            $this->put('/training-preferences', ['current_weight_kg' => 80, 'training_goals' => $goals])->assertSessionHasErrors();
            $this->assertSame($ownBefore, $user->fresh()->profile->getAttributes());
        }
        $this->assertDatabaseCount('body_weight_entries', 0);
    }

    public function test_using_same_plan_twice_copies_identity_media_modes_and_supersets_independently(): void
    {
        $user = User::factory()->create();
        $foreign = User::factory()->create();
        $this->actingAs($user);
        foreach ([1, 2] as $copy) {
            $this->post('/recommendations', ['preset' => 'upper-lower', 'user_id' => $foreign->id, 'workspace_type' => 'coach_template', 'schedule_enabled' => true])->assertSessionHasNoErrors();
        }
        $plans = $user->trainingPlans()->with('trainingDays.exercises')->orderBy('id')->get();
        $this->assertCount(2, $plans);
        $this->assertSame(0, $foreign->trainingPlans()->count());
        $catalog = collect(app(PlanRecommendations::class)->catalog())->firstWhere('key', 'upper-lower');
        foreach ($plans as $plan) {
            $this->assertSame('personal', $plan->workspace_type);
            $this->assertNull($plan->start_date);
            $this->assertFalse($plan->schedule_enabled);
            foreach ($plan->trainingDays as $i => $day) {
                $this->assertEmpty($day->weekdays);
                foreach ($day->exercises as $j => $exercise) {
                    $expected = $catalog['days'][$i]['exercises'][$j];
                    foreach (['name', 'sets', 'reps', 'notes', 'tracking_mode', 'superset_group', 'target_weight', 'icon'] as $field) {
                        $this->assertEquals($expected[$field], $exercise->$field);
                    }
                    $this->assertSame($expected['key'], $exercise->library_key);
                    Storage::disk('public')->assertExists($exercise->thumbnail_path);
                }
            }
        }
        $a = $plans[0]->trainingDays[0]->exercises[0];
        $b = $plans[1]->trainingDays[0]->exercises[0];
        $this->assertNotSame($a->thumbnail_path, $b->thumbnail_path);
        $a->update(['name' => 'My own name', 'sets' => 5, 'target_weight' => 80]);
        $this->assertNotSame($a->fresh()->name, $b->fresh()->name);
        $this->assertSame($catalog, collect(app(PlanRecommendations::class)->catalog())->firstWhere('key', 'upper-lower'));
        $this->assertDatabaseCount('exercise_library_items', 0);
        $this->assertDatabaseCount('schedule_entries', 0);
    }

    public function test_new_plan_rolls_back_when_thumbnail_copy_fails(): void
    {
        $user = User::factory()->create();
        $this->mock(ExerciseLibrary::class, function ($mock) {
            $mock->shouldReceive('copyMedia')->once()->andReturnUsing(function ($exercise, $field, $directory, &$paths) {
                $paths[] = 'exercise-thumbnails/failed-copy.jpg';
                Storage::disk('public')->put($paths[0], 'temporary');
                throw new \RuntimeException('Copy failed');
            });
        });
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($user)->post('/recommendations', ['preset' => 'full-body']);
            $this->fail('Expected media failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('Copy failed', $error->getMessage());
        }
        $this->assertDatabaseCount('training_plans', 0);
        $this->assertDatabaseCount('training_days', 0);
        $this->assertDatabaseCount('training_exercises', 0);
        Storage::disk('public')->assertMissing('exercise-thumbnails/failed-copy.jpg');
    }

    public static function accountTypes(): array
    {
        return [['trainee'], ['coach']];
    }

    #[DataProvider('accountTypes')]
    public function test_full_personal_lifecycle_and_returning_preferences_preserve_saved_workouts(string $type): void
    {
        $this->post('/register', ['name' => 'Discovery user', 'email' => $type.'@example.test', 'password' => 'test-password-123', 'password_confirmation' => 'test-password-123', 'account_type' => $type, 'display_name' => $type === 'coach' ? 'Discovery coach' : null])->assertSessionHasNoErrors();
        $user = User::where('email', $type.'@example.test')->firstOrFail();
        $this->post('/workspace', ['workspace' => 'personal'])->assertSessionHasNoErrors();
        $this->post('/onboarding', ['training_goals' => ['build_muscle', 'get_stronger', 'mobility'], 'current_weight_kg' => 80, 'experience_level' => 'beginner', 'training_days_per_week' => 3, 'equipment_access' => ['dumbbells'], 'session_duration_minutes' => 45])->assertRedirect('/recommendations');
        $this->get('/recommendations')->assertInertia(fn (Assert $page) => $page->has('plans', 19));
        $this->get('/recommendations/full-body')->assertInertia(fn (Assert $page) => $page->has('plan.days', 3));
        $this->post('/recommendations', ['preset' => 'full-body'])->assertSessionHasNoErrors();
        $plan = $user->trainingPlans()->firstOrFail();
        $this->assertDatabaseCount('schedule_entries', 0);
        $plan->update(['start_date' => today()->toDateString(), 'end_date' => today()->toDateString()]);
        $day = $plan->trainingDays()->first();
        $day->update(['weekdays' => [(int) today()->dayOfWeek], 'default_time' => '18:00']);
        $preview = app(PlanSchedule::class)->preview($plan->fresh());
        $this->get('/training-plans/'.$plan->id.'/schedule-preview')->assertOk();
        $this->post('/training-plans/'.$plan->id.'/schedule', ['signature' => $preview['signature'], 'action' => 'add', 'confirmed' => true])->assertSessionHasNoErrors();
        $entry = $user->scheduleEntries()->firstOrFail();
        $this->travelTo(now()->setTime(18, 1));
        $this->post('/schedule-entries/'.$entry->id.'/workout/start')->assertSessionHasNoErrors();
        foreach ($entry->fresh('sessionExercises.sets')->sessionExercises as $exercise) {
            foreach ($exercise->sets as $set) {
                $data = $exercise->tracking_mode === 'duration' ? ['actual_duration_seconds' => 30] : ['actual_reps' => 10, 'actual_weight_kg' => 0];
                $this->patch('/session-sets/'.$set->id, [...$data, 'completed' => true])->assertSessionHasNoErrors();
            }
        }
        $this->post('/schedule-entries/'.$entry->id.'/workout/finish', ['confirm_incomplete' => true])->assertSessionHasNoErrors();
        $this->get('/training-history/'.$entry->id)->assertInertia(fn (Assert $page) => $page->where('entry.status', 'completed'));
        $key = $day->exercises()->first()->library_key;
        $this->assertSame($key, $entry->sessionExercises()->first()->source_exercise_key);
        $workout = app(ExerciseProgress::class)->workouts($user, $key, 'reps')->first();
        $this->assertSame(20, $workout['total_reps']);
        $this->assertNull($workout['heaviest_weight']);
        $this->get('/progress/exercises?'.http_build_query(['exercise' => $key]))->assertOk();
        $before = [];
        foreach (['training_plans', 'training_days', 'training_exercises', 'schedule_entries', 'session_exercises', 'session_sets'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $onboarded = $user->fresh()->profile->onboarding_completed_at->toISOString();
        $this->put('/training-preferences', ['training_goals' => ['endurance', 'general_fitness', 'mobility'], 'current_weight_kg' => 80])->assertRedirect('/recommendations');
        foreach ($before as $table => $json) {
            $this->assertSame($json, DB::table($table)->orderBy('id')->get()->toJson());
        }
        $this->assertSame($onboarded, $user->fresh()->profile->onboarding_completed_at->toISOString());
        $this->assertDatabaseCount('body_weight_entries', 1);
        $this->post('/recommendations', ['preset' => 'endurance'])->assertSessionHasNoErrors();
        $this->assertSame(2, $user->trainingPlans()->count());
        $this->assertDatabaseCount('schedule_entries', 1);
        $this->assertSame($before['session_sets'], DB::table('session_sets')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('coach_plan_assignments', 0);
        $this->assertDatabaseCount('coach_subscriptions', 0);
        if ($type === 'coach') {
            $this->get('/coach')->assertOk();
            $this->assertSame('Discovery coach', $user->coachProfile->display_name);
        } else {
            $this->post('/profile/coach', ['display_name' => 'Forbidden'])->assertForbidden();
            $this->post('/workspace', ['workspace' => 'coach'])->assertForbidden();
        }
    }
}
