<?php

namespace Tests\Feature;

use App\Models\ExerciseLibraryItem;
use App\Models\ScheduleEntry;
use App\Models\SessionSet;
use App\Models\TrainingDay;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ActiveWorkoutTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private TrainingDay $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');
        $this->travelTo(now()->setDate(2026, 10, 27)->setTime(12, 0));
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        $plan = $this->user->trainingPlans()->create(['name' => 'Unchanged plan']);
        $this->day = $plan->trainingDays()->create(['title' => 'Strength and core', 'type' => 'training', 'focus_area' => 'Full Body']);
        $this->day->exercises()->create(['name' => 'Squat', 'sets' => 2, 'reps' => '8–10', 'target_weight' => 80, 'superset_group' => 'A', 'position' => 0]);
        $this->day->exercises()->create(['name' => 'Plank', 'sets' => 1, 'reps' => '45 seconds', 'tracking_mode' => 'duration', 'superset_group' => 'A', 'position' => 1]);
    }

    private function entry(array $attributes = []): ScheduleEntry
    {
        return $this->user->scheduleEntries()->create([
            'title' => 'Strength and core', 'entry_type' => 'training', 'status' => 'planned',
            'training_day_id' => $this->day->id, 'focus_area' => 'Full Body', 'scheduled_date' => today()->toDateString(), ...$attributes,
        ]);
    }

    private function start(?ScheduleEntry $entry = null): ScheduleEntry
    {
        $entry ??= $this->entry();
        $this->post("/schedule-entries/{$entry->id}/workout/start")->assertSessionHasNoErrors()->assertRedirect("/schedule-entries/{$entry->id}/workout");

        return $entry->fresh('sessionExercises.sets');
    }

    private function saveSet(SessionSet $set, array $data): void
    {
        $this->from('/dashboard')->patch("/session-sets/{$set->id}", $data)->assertSessionHasNoErrors()->assertRedirect('/dashboard');
    }

    private function finish(ScheduleEntry $entry, bool $confirmed = true): void
    {
        $this->post("/schedule-entries/{$entry->id}/workout/finish", ['confirm_incomplete' => $confirmed])->assertSessionHasNoErrors()->assertRedirect("/schedule-entries/{$entry->id}");
    }

    public function test_start_copies_prescription_and_creates_empty_actuals_idempotently(): void
    {
        $entry = $this->start();
        $this->assertTrue($entry->started_at->equalTo(now()));
        $this->assertSame('planned', $entry->status);
        $this->assertDatabaseCount('session_exercises', 2);
        $this->assertDatabaseCount('session_sets', 3);
        $exercise = $entry->sessionExercises->first();
        $this->assertSame('reps', $exercise->tracking_mode);
        $this->assertSame('80.00', $exercise->planned_weight_kg);
        $this->assertSame('A', $exercise->superset_group);
        foreach ($exercise->sets as $set) {
            $this->assertSame('8–10', $set->planned_reps_text);
            $this->assertNull($set->actual_reps);
            $this->assertNull($set->actual_weight_kg);
            $this->assertNull($set->completed_at);
        }
        $ids = SessionSet::pluck('id')->all();
        $this->travel(5)->minutes();
        $again = $this->start($entry);
        $this->assertTrue($entry->started_at->equalTo($again->started_at));
        $this->assertSame($ids, SessionSet::pluck('id')->all());
    }

    public static function invalidStarts(): array
    {
        return [['rest', 'planned'], ['training', 'completed'], ['training', 'skipped']];
    }

    #[DataProvider('invalidStarts')]
    public function test_only_planned_training_sessions_can_start(string $type, string $status): void
    {
        $entry = $this->entry(['entry_type' => $type, 'status' => $status]);
        $this->post("/schedule-entries/{$entry->id}/workout/start")->assertSessionHasErrors('workout');
        $this->assertDatabaseCount('session_exercises', 0);
        $this->assertNull($entry->fresh()->started_at);
    }

    public function test_a_second_start_redirects_to_the_existing_active_workout_including_other_dates(): void
    {
        $first = $this->start($this->entry(['scheduled_date' => today()->subDay()->toDateString()]));
        $second = $this->entry();
        $this->post("/schedule-entries/{$second->id}/workout/start")->assertRedirect("/schedule-entries/{$first->id}/workout");
        $this->assertNull($second->fresh()->started_at);
        $this->assertDatabaseCount('session_sets', 3);
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('activeWorkout.id', $first->id));
    }

    public function test_resume_and_history_render_saved_logs_after_navigation(): void
    {
        $entry = $this->start();
        $set = $entry->sessionExercises->first()->sets->first();
        $this->saveSet($set, ['actual_reps' => 10, 'actual_weight_kg' => '82.50', 'completed' => true]);
        $this->get('/calendar')->assertOk();
        foreach (["/schedule-entries/{$entry->id}/workout", "/schedule-entries/{$entry->id}", "/training-history/{$entry->id}"] as $path) {
            $this->get($path)->assertInertia(fn (Assert $page) => $page->where('entry.session_exercises.0.sets.0.actual_reps', 10)->where('entry.session_exercises.0.sets.0.actual_weight_kg', '82.50')->where('entry.session_exercises.0.sets.0.completed_at', fn ($value) => $value !== null));
        }
    }

    public function test_reps_are_required_to_complete_and_weight_is_optional(): void
    {
        $set = $this->start()->sessionExercises->first()->sets->first();
        $this->patch("/session-sets/{$set->id}", ['completed' => true])->assertSessionHasErrors('actual_reps');
        $this->saveSet($set, ['actual_reps' => 12, 'completed' => true]);
        $this->assertNull($set->fresh()->actual_weight_kg);
        $this->assertNotNull($set->fresh()->completed_at);
    }

    public function test_duration_mode_requires_seconds_and_accepts_optional_weight(): void
    {
        $set = $this->start()->sessionExercises->last()->sets->first();
        $this->patch("/session-sets/{$set->id}", ['actual_reps' => 20, 'completed' => true])->assertSessionHasErrors('actual_duration_seconds');
        $this->saveSet($set, ['actual_duration_seconds' => 90, 'actual_weight_kg' => 0, 'completed' => true]);
        $this->assertSame(90, $set->fresh()->actual_duration_seconds);
        $this->assertSame('0.00', $set->fresh()->actual_weight_kg);
        $this->assertNull($set->fresh()->actual_reps);
    }

    public static function invalidSetValues(): array
    {
        return [
            ['actual_reps', -1], ['actual_reps', 0], ['actual_reps', 1.5], ['actual_reps', 10001], ['actual_reps', 'many'],
            ['actual_duration_seconds', 0], ['actual_duration_seconds', -1], ['actual_duration_seconds', '01:30'], ['actual_duration_seconds', 86401],
            ['actual_weight_kg', -1], ['actual_weight_kg', '80.123'], ['actual_weight_kg', 10000],
        ];
    }

    #[DataProvider('invalidSetValues')]
    public function test_invalid_set_values_do_not_save(string $field, mixed $value): void
    {
        $set = $this->start()->sessionExercises->first()->sets->first();
        $this->patch("/session-sets/{$set->id}", [$field => $value])->assertSessionHasErrors($field);
        $this->assertNull($set->fresh()->completed_at);
    }

    public function test_drafts_edits_and_incomplete_preserve_values_and_completion_time(): void
    {
        $set = $this->start()->sessionExercises->first()->sets->first();
        $this->saveSet($set, ['actual_reps' => 10, 'actual_weight_kg' => 80]);
        $this->assertNull($set->fresh()->completed_at);
        $this->saveSet($set, ['completed' => true]);
        $time = $set->fresh()->completed_at;
        $this->travel(2)->minutes();
        $this->saveSet($set, ['actual_reps' => 9]);
        $this->assertTrue($set->fresh()->completed_at->equalTo($time));
        $this->patch("/session-sets/{$set->id}", ['actual_reps' => null])->assertSessionHasErrors('actual_reps');
        $this->saveSet($set, ['completed' => false]);
        $this->assertNull($set->fresh()->completed_at);
        $this->assertSame(9, $set->fresh()->actual_reps);
        $this->assertSame('80.00', $set->fresh()->actual_weight_kg);
    }

    public function test_exercise_completion_is_derived_and_adding_sets_reopens_it(): void
    {
        $exercise = $this->start()->sessionExercises->first();
        foreach ($exercise->sets as $set) {
            $this->saveSet($set, ['actual_reps' => 10, 'completed' => true]);
        }
        $this->assertSame('completed', $exercise->fresh()->status);
        $this->saveSet($exercise->sets->first(), ['completed' => false]);
        $this->assertSame('active', $exercise->fresh()->status);
        $this->saveSet($exercise->sets->first(), ['completed' => true]);
        $this->post("/session-exercises/{$exercise->id}/sets")->assertSessionHasNoErrors();
        $exercise->refresh();
        $this->assertSame('active', $exercise->status);
        $set = $exercise->sets->last();
        $this->assertSame(3, $set->set_number);
        $this->assertSame('80.00', $set->planned_weight_kg);
        $this->assertNull($set->actual_reps);
        $this->assertNull($set->actual_weight_kg);
        $this->assertSame(2, $this->day->exercises->first()->sets);
    }

    public function test_removing_completed_sets_requires_confirmation_and_renumbers(): void
    {
        $exercise = $this->start()->sessionExercises->first();
        $this->post("/session-exercises/{$exercise->id}/sets");
        $middle = $exercise->sets->last();
        $this->saveSet($middle, ['actual_reps' => 10, 'completed' => true]);
        $this->delete("/session-sets/{$middle->id}")->assertSessionHasErrors('confirmed');
        $this->delete("/session-sets/{$middle->id}", ['confirmed' => true])->assertSessionHasNoErrors();
        $this->assertSame([1, 2], $exercise->sets()->pluck('set_number')->all());
        $this->assertSame(2, $this->day->exercises->first()->sets);
        foreach ($exercise->sets()->get() as $set) {
            $this->delete("/session-sets/{$set->id}")->assertSessionHasNoErrors();
        }
        $this->assertSame('active', $exercise->fresh()->status);
    }

    public function test_skip_undo_and_finish_keep_completed_results_and_skipped_exercises(): void
    {
        $entry = $this->start();
        $exercise = $entry->sessionExercises->first();
        $this->saveSet($exercise->sets->first(), ['actual_reps' => 8, 'completed' => true]);
        $this->patch("/session-exercises/{$exercise->id}/status", ['status' => 'skipped'])->assertSessionHasErrors('confirmed');
        $this->patch("/session-exercises/{$exercise->id}/status", ['status' => 'skipped', 'confirmed' => true])->assertSessionHasNoErrors();
        $this->patch('/session-sets/'.$exercise->sets->last()->id, ['actual_reps' => 8])->assertSessionHasErrors('workout');
        $this->post("/session-exercises/{$exercise->id}/sets")->assertSessionHasErrors('workout');
        $this->patch("/session-exercises/{$exercise->id}/status", ['status' => 'active'])->assertSessionHasNoErrors();
        $this->assertSame('active', $exercise->fresh()->status);
        $this->patch("/session-exercises/{$exercise->id}/status", ['status' => 'skipped', 'confirmed' => true]);
        $this->finish($entry);
        $this->get("/training-history/{$entry->id}")->assertInertia(fn (Assert $page) => $page->where('entry.session_exercises.0.status', 'skipped')->where('entry.session_exercises.0.sets.0.actual_reps', 8));
    }

    public function test_finish_requires_explicit_confirmation_for_incomplete_unskipped_sets(): void
    {
        $entry = $this->start();
        $this->post("/schedule-entries/{$entry->id}/workout/finish")->assertSessionHasErrors('incomplete_sets');
        $this->assertSame('planned', $entry->fresh()->status);
        $this->finish($entry);
        $this->assertSame('completed', $entry->fresh()->status);
        $this->assertSame(0, SessionSet::whereNotNull('completed_at')->count());
        $this->assertSame(1, $entry->fresh()->duration_minutes);
    }

    public function test_finish_uses_elapsed_duration_calorie_estimator_and_preserves_reported_calories(): void
    {
        $this->user->profile()->create(['current_weight_kg' => 80]);
        $entry = $this->start($this->entry(['calories_burned' => 450, 'calories_source' => 'wearable']));
        $snapshot = $entry->workout_snapshot;
        foreach ($entry->sessionExercises as $exercise) {
            foreach ($exercise->sets as $set) {
                $this->saveSet($set, [$exercise->tracking_mode === 'duration' ? 'actual_duration_seconds' : 'actual_reps' => 10, 'completed' => true]);
            }
        }
        $this->travel(55)->minutes();
        $this->finish($entry, false);
        $entry->refresh();
        $this->assertSame(55, $entry->duration_minutes);
        $this->assertSame(270, $entry->estimated_calories);
        $this->assertSame(450, $entry->calories_burned);
        $this->assertSame('wearable', $entry->calories_source);
        $this->assertTrue($entry->completed_at->equalTo(now()));
        $this->assertSame($snapshot, $entry->workout_snapshot);
        $this->assertSame(3, SessionSet::whereNotNull('completed_at')->count());
        $this->get('/training-history')->assertInertia(fn (Assert $page) => $page->where('entries.data.0.completed_sets_count', 3)->where('entries.data.0.detailed_workout', true)->where('filters.per_page', '10'));
    }

    public function test_quick_completion_is_preserved_but_cannot_bypass_active_workout(): void
    {
        $quick = $this->entry();
        $this->patch("/schedule-entries/{$quick->id}/status", ['status' => 'completed'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('session_sets', 0);
        $this->patch("/schedule-entries/{$quick->id}/weights", ['weights' => [['index' => 0, 'actual_weight' => 85]]])->assertSessionHasNoErrors();
        $active = $this->start();
        foreach (['completed', 'skipped'] as $status) {
            $this->patch("/schedule-entries/{$active->id}/status", ['status' => $status])->assertSessionHasErrors('status');
        }
        $this->assertSame('planned', $active->fresh()->status);
        $this->finish($active);
        $this->patch("/schedule-entries/{$active->id}/weights", ['weights' => [['index' => 0, 'actual_weight' => 90]]])->assertSessionHasErrors('weights');
    }

    public function test_finished_workout_rejects_logging_and_reopening_reuses_records(): void
    {
        $entry = $this->start();
        $set = $entry->sessionExercises->first()->sets->first();
        $this->saveSet($set, ['actual_reps' => 9, 'completed' => true]);
        $this->finish($entry);
        $this->patch("/session-sets/{$set->id}", ['actual_reps' => 10])->assertSessionHasErrors('workout');
        $this->post("/schedule-entries/{$entry->id}/workout/finish", ['confirm_incomplete' => true])->assertSessionHasErrors('workout');
        $this->get("/schedule-entries/{$entry->id}/workout")->assertRedirect("/schedule-entries/{$entry->id}");
        $ids = SessionSet::pluck('id')->all();
        $this->patch("/schedule-entries/{$entry->id}/status", ['status' => 'planned'])->assertSessionHasNoErrors();
        $this->assertNull($entry->fresh()->started_at);
        $this->start($entry);
        $this->assertSame($ids, SessionSet::pluck('id')->all());
        $this->assertSame(9, $set->fresh()->actual_reps);
    }

    public function test_template_edits_deletion_and_media_removal_do_not_change_active_workout(): void
    {
        Storage::disk('public')->put('exercise-thumbnails/original.jpg', 'image');
        Storage::disk('public')->put('exercise-icons/original.jpg', 'icon');
        $this->day->exercises()->first()->update(['thumbnail_path' => 'exercise-thumbnails/original.jpg', 'custom_icon_path' => 'exercise-icons/original.jpg', 'library_key' => 'builtin:Barbell_Squat']);
        $entry = $this->start();
        $exercise = $entry->sessionExercises->first();
        $this->assertSame('builtin:Barbell_Squat', $exercise->source_exercise_key);
        $this->delete('/training-plans/'.$this->day->training_plan_id)->assertSessionHasNoErrors();
        $exercise->refresh();
        $this->assertNull($exercise->source_training_exercise_id);
        $this->assertSame('Squat', $exercise->name);
        $this->assertSame('80.00', $exercise->planned_weight_kg);
        Storage::disk('public')->assertExists($exercise->thumbnail_path);
        Storage::disk('public')->assertExists($exercise->custom_icon_path);
        $this->finish($entry);
        $this->assertSame($entry->workout_snapshot, $entry->fresh()->workout_snapshot);
        $this->delete("/schedule-entries/{$entry->id}")->assertSessionHasNoErrors();
        $this->assertDatabaseCount('session_sets', 0);
        $this->assertDatabaseCount('session_exercises', 0);
        Storage::disk('public')->assertMissing($exercise->thumbnail_path);
    }

    public function test_custom_identity_is_copied_and_manual_identity_can_be_null(): void
    {
        $custom = ExerciseLibraryItem::create(['user_id' => $this->user->id, 'name' => 'My squat']);
        $this->day->exercises()->first()->update(['library_key' => 'custom:'.$custom->id]);
        $entry = $this->start();
        $this->assertSame('custom:'.$custom->id, $entry->sessionExercises->first()->source_exercise_key);
        $this->assertNull($entry->sessionExercises->last()->source_exercise_key);
        $this->assertSame('custom:'.$custom->id, $entry->workout_snapshot['exercises'][0]['source_exercise_key']);
        $this->day->exercises()->update(['name' => 'Changed', 'library_key' => null, 'target_weight' => 100]);
        $this->assertSame('custom:'.$custom->id, $entry->sessionExercises->first()->fresh()->source_exercise_key);
    }

    public function test_legacy_snapshot_takes_precedence_and_is_never_rewritten(): void
    {
        $snapshot = ['version' => 1, 'exercises' => [['name' => 'Saved old exercise', 'sets' => 1, 'reps' => '20 sec', 'target_weight' => 10, 'actual_weight' => 12]]];
        $entry = $this->entry(['workout_snapshot' => $snapshot]);
        $snapshot = $entry->fresh()->workout_snapshot;
        $entry = $this->start($entry);
        $this->assertSame('Saved old exercise', $entry->sessionExercises->first()->name);
        $this->assertNull($entry->sessionExercises->first()->source_exercise_key);
        $this->assertSame('reps', $entry->sessionExercises->first()->tracking_mode);
        $this->assertNull($entry->sessionExercises->first()->sets->first()->actual_weight_kg);
        $this->finish($entry);
        $this->assertSame($snapshot, $entry->fresh()->workout_snapshot);
    }

    public function test_legacy_completed_history_without_snapshot_or_logs_still_renders(): void
    {
        $entry = $this->entry(['status' => 'completed']);
        $this->get("/training-history/{$entry->id}")->assertInertia(fn (Assert $page) => $page->where('workout', null)->has('entry.session_exercises', 0));
        $this->assertDatabaseCount('session_sets', 0);
    }

    public function test_discard_requires_confirmation_and_keeps_the_plan_session_and_snapshot(): void
    {
        $entry = $this->start();
        $this->saveSet($entry->sessionExercises->first()->sets->first(), ['actual_reps' => 10, 'completed' => true]);
        $this->post("/schedule-entries/{$entry->id}/workout/discard")->assertSessionHasErrors('confirmed');
        $this->post("/schedule-entries/{$entry->id}/workout/discard", ['confirmed' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('session_sets', 0);
        $this->assertDatabaseCount('session_exercises', 0);
        $this->assertDatabaseCount('training_exercises', 2);
        $this->assertSame('planned', $entry->fresh()->status);
        $this->assertNull($entry->fresh()->started_at);
        $this->assertSame($entry->workout_snapshot, $entry->fresh()->workout_snapshot);
        $again = $this->start($entry);
        $this->assertNull($again->sessionExercises->first()->sets->first()->actual_reps);
    }

    public function test_logging_never_changes_templates_and_ignores_forged_relational_fields(): void
    {
        $before = $this->day->fresh('exercises')->toArray();
        $entry = $this->start();
        $set = $entry->sessionExercises->first()->sets->first();
        $this->saveSet($set, ['actual_reps' => 12, 'actual_weight_kg' => 95, 'completed' => true, 'session_exercise_id' => 999, 'planned_weight_kg' => 999, 'set_number' => 999]);
        $this->assertSame('80.00', $set->fresh()->planned_weight_kg);
        $this->assertSame(1, $set->fresh()->set_number);
        $this->assertSame($before, $this->day->fresh('exercises')->toArray());
    }

    public function test_all_workout_and_set_routes_enforce_owner_authorization(): void
    {
        $entry = $this->start();
        $exercise = $entry->sessionExercises->first();
        $set = $exercise->sets->first();
        $this->actingAs(User::factory()->create());
        foreach (['start', 'finish', 'discard'] as $action) {
            $this->post("/schedule-entries/{$entry->id}/workout/$action", ['confirmed' => true, 'confirm_incomplete' => true])->assertForbidden();
        }
        $this->get("/schedule-entries/{$entry->id}/workout")->assertForbidden();
        $this->patch("/session-exercises/{$exercise->id}/status", ['status' => 'skipped'])->assertForbidden();
        $this->post("/session-exercises/{$exercise->id}/sets")->assertForbidden();
        $this->patch("/session-sets/{$set->id}", ['actual_reps' => 10])->assertForbidden();
        $this->delete("/session-sets/{$set->id}")->assertForbidden();
        $this->assertDatabaseCount('session_sets', 3);
    }

    public function test_tracking_mode_can_be_saved_in_existing_editor_and_invalid_modes_rejected(): void
    {
        $payload = ['title' => 'Modes', 'type' => 'training', 'exercises' => [
            ['name' => 'Plank', 'sets' => 1, 'reps' => '45 sec', 'tracking_mode' => 'duration'],
            ['name' => 'Squat', 'sets' => 3, 'reps' => '8'],
        ]];
        $this->post('/training-plans/'.$this->day->training_plan_id.'/days', $payload)->assertSessionHasNoErrors();
        $day = TrainingDay::where('title', 'Modes')->firstOrFail();
        $this->assertSame(['duration', 'reps'], $day->exercises->pluck('tracking_mode')->all());
        $payload['exercises'][0]['tracking_mode'] = 'calories';
        $this->put("/training-days/{$day->id}", $payload)->assertSessionHasErrors('exercises.0.tracking_mode');
        $this->assertSame(['duration', 'reps'], $day->fresh()->exercises->pluck('tracking_mode')->all());
    }

    public function test_active_session_cannot_switch_training_day_or_type(): void
    {
        $entry = $this->start();
        $this->patch("/schedule-entries/{$entry->id}", ['entry_type' => 'rest', 'training_day_id' => null, 'scheduled_date' => today()->toDateString()])->assertSessionHasErrors('training_day_id');
        $this->assertSame('training', $entry->fresh()->entry_type);
    }

    public function test_skipped_sets_do_not_require_finish_confirmation(): void
    {
        $entry = $this->start();
        foreach ($entry->sessionExercises as $exercise) {
            $this->patch("/session-exercises/{$exercise->id}/status", ['status' => 'skipped'])->assertSessionHasNoErrors();
        }
        $this->finish($entry, false);
        $this->assertSame(0, SessionSet::whereNotNull('completed_at')->count());
    }

    public function test_empty_workout_can_be_started_and_finished_without_fabricated_sets(): void
    {
        $this->day->exercises()->delete();
        $entry = $this->start();
        $this->start($entry);
        $this->finish($entry, false);
        $this->assertDatabaseCount('session_sets', 0);
        $this->assertSame([], $entry->fresh()->workout_snapshot['exercises']);
    }

    public function test_abandoned_duration_exceeding_the_existing_column_limit_returns_validation(): void
    {
        $entry = $this->start();
        $this->travel(46)->days();
        $this->post("/schedule-entries/{$entry->id}/workout/finish", ['confirm_incomplete' => true])->assertSessionHasErrors('workout');
        $this->assertSame('planned', $entry->fresh()->status);
        $this->assertDatabaseCount('session_sets', 3);
    }
}
