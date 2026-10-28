<?php

namespace Tests\Feature;

use App\Models\ScheduleEntry;
use App\Models\TrainingExercise;
use App\Models\User;
use App\Services\ExerciseProgression;
use App\Services\ProgressionPrescription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExerciseProgressionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private TrainingExercise $template;

    private ExerciseProgression $progression;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        $this->template = $this->template();
        $this->progression = app(ExerciseProgression::class);
    }

    private function template(array $attributes = [], ?User $owner = null): TrainingExercise
    {
        $plan = ($owner ?? $this->user)->trainingPlans()->create(['name' => 'Strength plan']);
        $day = $plan->trainingDays()->create(['title' => 'Lower body', 'type' => 'training']);

        return $day->exercises()->create([
            'name' => 'Squat', 'library_key' => 'builtin:Barbell_Full_Squat', 'tracking_mode' => 'reps',
            'sets' => 4, 'reps' => '8-10', 'target_weight' => 80, 'position' => 0, 'notes' => 'Keep these notes', ...$attributes,
        ]);
    }

    private function workout(?array $sets = null, array $exercise = [], array $entry = [], ?TrainingExercise $template = null): ScheduleEntry
    {
        $template ??= $this->template;
        $session = (isset($entry['user_id']) ? User::findOrFail($entry['user_id']) : $this->user)->scheduleEntries()->create([
            'training_day_id' => $template->training_day_id, 'title' => 'Recorded workout', 'entry_type' => 'training',
            'status' => 'completed', 'scheduled_date' => today()->toDateString(), 'completed_at' => now(),
            'workout_snapshot' => ['version' => 1, 'exercises' => [['id' => $template->id, 'reps' => $template->reps, 'target_weight' => $template->target_weight]]], ...$entry,
        ]);
        $movement = $session->sessionExercises()->create([
            'source_training_exercise_id' => $template->id, 'source_exercise_key' => $template->library_key,
            'name' => $template->name, 'tracking_mode' => $template->tracking_mode, 'planned_sets' => $template->sets,
            'planned_reps_text' => $template->reps, 'planned_weight_kg' => $template->target_weight,
            'position' => 0, 'status' => 'completed', ...$exercise,
        ]);
        foreach ($sets ?? array_fill(0, 4, []) as $i => $set) {
            $movement->sets()->create([
                'set_number' => $i + 1, 'planned_reps_text' => '999', 'planned_weight_kg' => 999,
                'actual_reps' => $template->tracking_mode === 'reps' ? 10 : null,
                'actual_duration_seconds' => $template->tracking_mode === 'duration' ? 60 : null,
                'actual_weight_kg' => $template->target_weight, 'completed_at' => now(), ...$set,
            ]);
        }

        return $session;
    }

    private function suggestion(?TrainingExercise $template = null): array
    {
        return $this->progression->forTemplate($this->user, ($template ?? $this->template)->fresh());
    }

    private function url(?TrainingExercise $template = null): string
    {
        $template ??= $this->template;

        return '/progress/exercises?'.http_build_query(['exercise' => $template->library_key, 'mode' => $template->tracking_mode, 'template' => $template->id]);
    }

    private function apply(array $suggestion, mixed $value = '82.5', ?TrainingExercise $template = null)
    {
        $template ??= $this->template;

        return $this->from($this->url($template))->patch("/training-exercises/{$template->id}/progression", ['token' => $suggestion['token'], 'value' => $value]);
    }

    public function test_two_latest_successful_workouts_use_current_targets_and_decimal_weights(): void
    {
        $this->template->update(['target_weight' => 80.25]);
        $this->workout(array_fill(0, 4, ['actual_weight_kg' => 80.25]));
        $this->workout(array_fill(0, 4, ['actual_weight_kg' => 82.75, 'actual_reps' => 11]));
        $result = $this->suggestion();
        $this->assertSame('increase_weight', $result['type']);
        $this->assertSame('82.75', $result['proposed_value']);
        $this->assertTrue($result['can_apply']);
        $this->assertSame('80.25', $this->template->fresh()->target_weight);
        $this->get($this->url())->assertInertia(fn (Assert $p) => $p->where('progression.selected_id', $this->template->id)->where('progression.suggestion.type', 'increase_weight'));
    }

    public function test_zero_or_one_performance_is_insufficient_even_with_a_pr(): void
    {
        $this->assertSame('insufficient_data', $this->suggestion()['type']);
        $this->workout(array_fill(0, 4, ['actual_weight_kg' => 100]));
        $result = $this->suggestion();
        $this->assertSame('insufficient_data', $result['type']);
        $this->assertSame(1, $result['performances']);
        $this->assertFalse($result['can_apply']);
    }

    public static function unsuccessful(): array
    {
        return [
            'zero weight' => [array_fill(0, 4, ['actual_weight_kg' => 0]), 'hold_weight'],
            'missing weight' => [array_fill(0, 4, ['actual_weight_kg' => null]), 'hold_weight'],
            'low weight' => [array_fill(0, 4, ['actual_weight_kg' => 79]), 'hold_weight'],
            'inside range' => [[['actual_reps' => 10], ['actual_reps' => 9], ['actual_reps' => 9], ['actual_reps' => 8]], 'hold_weight'],
            'below minimum' => [[[], [], [], ['actual_reps' => 7]], 'build_reps'],
            'one excellent set' => [[['actual_weight_kg' => 150, 'actual_reps' => 20]], 'build_reps'],
            'incomplete set' => [[[], [], [], ['completed_at' => null]], 'build_reps'],
            'all incomplete' => [array_fill(0, 4, ['completed_at' => null]), 'build_reps'],
            'missing reps' => [array_fill(0, 4, ['actual_reps' => null]), 'build_reps'],
            'zero reps' => [array_fill(0, 4, ['actual_reps' => 0]), 'build_reps'],
            'no sets' => [[], 'build_reps'],
        ];
    }

    #[DataProvider('unsuccessful')]
    public function test_latest_unsuccessful_occurrence_blocks_two_older_successes(array $sets, string $type): void
    {
        $this->workout();
        $this->workout();
        $this->workout($sets, ['status' => 'active']);
        $result = $this->suggestion();
        $this->assertSame($type, $result['type']);
        $this->assertFalse($result['can_apply']);
        $this->assertNull($result['proposed_value']);
        $this->assertSame(2, $result['performances']);
    }

    public function test_extra_weak_or_incomplete_sets_do_not_make_the_set_count_stricter(): void
    {
        $this->workout();
        $this->workout([[], [], [], [], ['actual_reps' => 2, 'actual_weight_kg' => 10], ['completed_at' => null]], ['status' => 'active']);
        $this->assertSame('increase_weight', $this->suggestion()['type']);
    }

    public function test_skipped_exercises_other_modes_keys_users_and_noncompleted_sessions_are_excluded(): void
    {
        $this->workout();
        $this->workout();
        $this->workout([], ['status' => 'skipped']);
        $this->workout([], ['tracking_mode' => 'duration']);
        $this->workout([], ['source_exercise_key' => 'custom:different']);
        $this->workout([], [], ['status' => 'planned']);
        $this->workout([], [], ['status' => 'skipped']);
        $this->workout([], [], ['entry_type' => 'rest']);
        $this->workout([], [], ['user_id' => User::factory()->create()->id]);
        $this->assertSame('increase_weight', $this->suggestion()['type']);
    }

    public function test_status_reversal_and_recompletion_use_final_current_results(): void
    {
        $this->workout();
        $last = $this->workout();
        foreach (['planned', 'skipped'] as $status) {
            $last->update(['status' => $status]);
            $this->assertSame('insufficient_data', $this->suggestion()['type']);
        }
        $last->sessionExercises()->first()->sets()->first()->update(['actual_reps' => 8]);
        $last->update(['status' => 'completed']);
        $this->assertSame('hold_weight', $this->suggestion()['type']);
        $last->sessionExercises()->first()->sets()->first()->update(['actual_reps' => 10]);
        $this->assertSame('increase_weight', $this->suggestion()['type']);
    }

    public function test_recency_follows_scheduled_date_time_and_id_not_insertion_or_best_results(): void
    {
        $this->workout([], [], ['scheduled_date' => today()->subDays(3)]);
        $this->workout([], [], ['scheduled_time' => '08:00']);
        $this->workout(null, [], ['scheduled_time' => '10:00']);
        $this->workout(null, [], ['scheduled_time' => '10:00']);
        $this->assertSame('increase_weight', $this->suggestion()['type']);
        $this->workout([], [], ['scheduled_time' => '10:00']);
        $this->assertSame('build_reps', $this->suggestion()['type']);
    }

    public function test_no_legacy_snapshot_or_unlinked_identity_results_are_invented(): void
    {
        $this->workout(null, ['source_training_exercise_id' => null]);
        $this->workout(null, ['source_exercise_key' => null]);
        $entry = $this->workout();
        $entry->sessionExercises()->delete();
        $this->assertSame(0, $this->suggestion()['performances']);
    }

    public function test_multiple_templates_require_selection_and_use_only_their_own_history(): void
    {
        $this->workout();
        $this->workout();
        $other = $this->template(['sets' => 2, 'reps' => '12', 'target_weight' => 90]);
        $this->workout([['actual_reps' => 12], ['actual_reps' => 12]], template: $other);
        $this->workout([['actual_reps' => 12], ['actual_reps' => 12]], template: $other);
        $result = $this->progression->detail($this->user, $this->template->library_key, 'reps');
        $this->assertCount(2, $result['templates']);
        $this->assertNull($result['suggestion']);
        $this->assertNull($result['selected_id']);
        $this->assertSame('82.5', $this->suggestion()['proposed_value']);
        $this->assertSame('92.5', $this->suggestion($other)['proposed_value']);
        $new = $this->template();
        $this->assertSame(0, $this->suggestion($new)['performances']);
    }

    public function test_same_name_different_key_and_mode_are_not_matching_templates(): void
    {
        $this->template(['library_key' => 'custom:other']);
        $this->template(['tracking_mode' => 'duration']);
        $this->template([], User::factory()->create());
        $result = $this->progression->detail($this->user, $this->template->library_key, 'reps');
        $this->assertCount(1, $result['templates']);
        $this->assertSame($this->template->id, $result['selected_id']);
    }

    public function test_legacy_null_tracking_mode_remains_reps_without_rewriting_template(): void
    {
        $this->template->update(['tracking_mode' => null]);
        $sets = array_fill(0, 4, ['actual_reps' => 10]);
        $this->workout($sets, ['tracking_mode' => 'reps']);
        $this->workout($sets, ['tracking_mode' => 'reps']);
        $result = $this->progression->detail($this->user, $this->template->library_key, 'reps');
        $this->assertSame($this->template->id, $result['selected_id']);
        $this->assertSame('increase_weight', $result['suggestion']['type']);
        $this->apply($result['suggestion'])->assertSessionHasNoErrors();
        $this->assertNull($this->template->fresh()->tracking_mode);
    }

    public static function nonweighted(): array
    {
        return [
            'reps range' => ['reps', '8-10', 0, 'increase_reps', '9-11'],
            'reps single' => ['reps', '10', null, 'increase_reps', '11'],
            'duration' => ['duration', '45 sec', null, 'increase_duration', '50 sec'],
            'minutes' => ['duration', '1 min', null, 'increase_duration', '65 sec'],
        ];
    }

    #[DataProvider('nonweighted')]
    public function test_reps_and_duration_progression_enforce_sets_and_preserve_other_fields(string $mode, string $reps, ?int $weight, string $type, string $next): void
    {
        $this->template->update(['tracking_mode' => $mode, 'reps' => $reps, 'target_weight' => $weight]);
        $this->workout();
        $this->workout();
        $result = $this->suggestion();
        $this->assertSame($type, $result['type']);
        $this->assertSame($next, $result['proposed_value']);
        $before = $this->template->fresh()->getAttributes();
        $this->apply($result, $next)->assertSessionHasNoErrors();
        $after = $this->template->fresh()->getAttributes();
        $this->assertSame($next, $after['reps']);
        unset($before['reps'], $after['reps'], $before['updated_at'], $after['updated_at']);
        $this->assertSame($before, $after);
        $this->workout([[], [], []]);
        $this->assertSame('build_reps', $this->suggestion()['type']);
    }

    public static function targets(): array
    {
        return [
            'weight boundary' => ['reps', '10', 9999, 10, null],
            'reps boundary' => ['reps', '10000', null, 10000, null],
            'duration boundary' => ['duration', '86398 sec', null, null, 86400],
            'AMRAP' => ['reps', 'AMRAP', 80, 100, null],
            'reversed' => ['reps', '10-8', 80, 100, null],
            'ambiguous duration' => ['duration', '30 sec each side', null, null, 300],
        ];
    }

    #[DataProvider('targets')]
    public function test_unsupported_or_maximum_targets_never_propose_invalid_values(string $mode, string $text, ?int $weight, ?int $reps, ?int $duration): void
    {
        $this->template->update(['tracking_mode' => $mode, 'reps' => $text, 'target_weight' => $weight]);
        $sets = array_fill(0, 4, ['actual_reps' => $reps, 'actual_duration_seconds' => $duration]);
        $this->workout($sets);
        $this->workout($sets);
        $result = $this->suggestion();
        $this->assertFalse($result['can_apply']);
        $this->assertNull($result['proposed_value']);
        $this->assertSame($text, $this->template->fresh()->reps);
    }

    public function test_duration_below_target_holds_without_weight_or_reps_language(): void
    {
        $this->template->update(['tracking_mode' => 'duration', 'reps' => '1:30', 'target_weight' => null]);
        $this->workout();
        $this->workout();
        $result = $this->suggestion();
        $this->assertSame('hold_weight', $result['type']);
        $this->assertSame('Keep current target', $result['title']);
        $this->assertStringNotContainsString('reps', $result['reason']);
    }

    public function test_deleted_template_has_readonly_historical_suggestion_and_ambiguous_history_has_none(): void
    {
        $this->workout();
        $this->workout();
        $key = $this->template->library_key;
        $this->template->delete();
        $result = $this->progression->detail($this->user, $key, 'reps');
        $this->assertCount(0, $result['templates']);
        $this->assertTrue($result['suggestion']['historical']);
        $this->assertSame('82.5', $result['suggestion']['proposed_value']);
        $this->assertFalse($result['suggestion']['can_apply']);
        $this->assertNull($result['suggestion']['token']);
        DB::table('session_exercises')->where('id', DB::table('session_exercises')->min('id'))->update(['planned_sets' => 2]);
        $this->assertNull($this->progression->detail($this->user, $key, 'reps')['suggestion']);
        $this->assertSame(0, TrainingExercise::count());
    }

    public static function staleChanges(): array
    {
        return array_map(fn ($x) => [$x], ['weight', 'reps', 'sets', 'mode', 'key', 'status', 'actuals', 'completion', 'new_workout', 'delete_workout', 'add_set']);
    }

    #[DataProvider('staleChanges')]
    public function test_apply_recalculates_and_rejects_changed_evidence(string $change): void
    {
        $this->workout();
        $last = $this->workout();
        $result = $this->suggestion();
        $movement = $last->sessionExercises()->first();
        match ($change) {
            'weight' => $this->template->update(['target_weight' => 79]),
            'reps' => $this->template->update(['reps' => '9-10']),
            'sets' => $this->template->update(['sets' => 3]),
            'mode' => $this->template->update(['tracking_mode' => 'duration']),
            'key' => $this->template->update(['library_key' => 'custom:changed']),
            'status' => $last->update(['status' => 'skipped']),
            'actuals' => $movement->sets()->first()->update(['actual_reps' => 11]),
            'completion' => $movement->sets()->first()->update(['completed_at' => null]),
            'new_workout' => $this->workout(),
            'delete_workout' => $last->delete(),
            'add_set' => $movement->sets()->create(['set_number' => 5, 'actual_reps' => 10, 'actual_weight_kg' => 80, 'completed_at' => now()]),
        };
        $before = $this->template->fresh()->target_weight;
        $this->apply($result)->assertSessionHasErrors('progression');
        $this->assertSame($before, $this->template->fresh()->target_weight);
    }

    public function test_duplicate_apply_does_not_increase_twice_and_user_can_edit_proposed_weight(): void
    {
        $this->workout();
        $this->workout();
        $result = $this->suggestion();
        $this->apply($result, '81')->assertSessionHasNoErrors();
        $this->assertSame('81.00', $this->template->fresh()->target_weight);
        $this->apply($result, '81')->assertSessionHasErrors('progression');
        $this->assertSame('81.00', $this->template->fresh()->target_weight);
    }

    public static function invalidWeights(): array
    {
        return [[0], [null], [-1], ['NaN'], ['Infinity'], [10000], ['82.555'], [['82.5']]];
    }

    #[DataProvider('invalidWeights')]
    public function test_invalid_applied_weight_is_rejected(mixed $value): void
    {
        $this->workout();
        $this->workout();
        $this->apply($this->suggestion(), $value)->assertSessionHasErrors('value');
        $this->assertSame('80.00', $this->template->fresh()->target_weight);
    }

    public function test_invalid_reps_and_duration_cannot_be_applied(): void
    {
        foreach (['reps' => '8-10', 'duration' => '45 sec'] as $mode => $target) {
            $this->template->update(['tracking_mode' => $mode, 'reps' => $target, 'target_weight' => null]);
            $this->workout();
            $this->workout();
            $this->apply($this->suggestion(), $mode === 'reps' ? 'AMRAP' : '0 sec')->assertSessionHasErrors('value');
            $this->assertSame($target, $this->template->fresh()->reps);
        }
    }

    public function test_ownership_identity_and_token_cannot_be_bypassed(): void
    {
        $this->workout();
        $this->workout();
        $result = $this->suggestion();
        $other = $this->template([], User::factory()->create());
        $this->apply($result, '82.5', $other)->assertForbidden();
        $this->get($this->url($other))->assertNotFound();
        $ownCopy = $this->template();
        $this->workout(template: $ownCopy);
        $this->workout(template: $ownCopy);
        $this->apply($result, '82.5', $ownCopy)->assertSessionHasErrors('progression');
        $this->apply([...$result, 'token' => str_repeat('a', 64)])->assertSessionHasErrors('progression');
        auth()->logout();
        $this->apply($result)->assertRedirect('/login');
    }

    public function test_hold_or_insufficient_suggestions_cannot_be_applied_even_with_valid_token(): void
    {
        $this->apply($this->suggestion())->assertSessionHasErrors('progression');
        $this->workout();
        $this->workout(array_fill(0, 4, ['actual_reps' => 8]));
        $this->apply($this->suggestion())->assertSessionHasErrors('progression');
    }

    public function test_apply_changes_only_selected_target_and_preserves_active_history_and_future_schedule(): void
    {
        $this->workout();
        $this->workout();
        $other = $this->template();
        $active = $this->user->scheduleEntries()->create(['training_day_id' => $this->template->training_day_id, 'title' => 'Active', 'entry_type' => 'training', 'status' => 'planned', 'scheduled_date' => today()]);
        $this->post("/schedule-entries/{$active->id}/workout/start")->assertSessionHasNoErrors();
        $future = $this->user->scheduleEntries()->create(['training_day_id' => $this->template->training_day_id, 'title' => 'Future', 'entry_type' => 'training', 'status' => 'planned', 'scheduled_date' => today()->addDay()]);
        $snapshot = fn () => collect(['schedule_entries', 'session_exercises', 'session_sets'])->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
        $before = $snapshot();
        $templateBefore = $this->template->fresh()->getAttributes();
        $this->apply($this->suggestion())->assertSessionHasNoErrors();
        $this->assertSame($before, $snapshot());
        $after = $this->template->fresh()->getAttributes();
        unset($templateBefore['target_weight'], $templateBefore['updated_at'], $after['target_weight'], $after['updated_at']);
        $this->assertSame($templateBefore, $after);
        $this->assertSame('80.00', $other->fresh()->target_weight);
        $this->assertSame('80.00', $active->sessionExercises()->first()->planned_weight_kg);
        $active->update(['status' => 'skipped', 'started_at' => null]);
        $this->post("/schedule-entries/{$future->id}/workout/start")->assertSessionHasNoErrors();
        $this->assertSame('82.50', $future->sessionExercises()->first()->planned_weight_kg);
    }

    public function test_saved_prescription_on_reopened_session_is_preserved_after_apply(): void
    {
        $old = $this->workout();
        $this->workout();
        $this->apply($this->suggestion())->assertSessionHasNoErrors();
        $this->patch("/schedule-entries/{$old->id}/status", ['status' => 'planned'])->assertSessionHasNoErrors();
        $this->post("/schedule-entries/{$old->id}/workout/start")->assertSessionHasNoErrors();
        $this->assertSame('80.00', $old->sessionExercises()->first()->planned_weight_kg);
        $this->assertSame('80.00', $old->fresh()->workout_snapshot['exercises'][0]['target_weight']);
    }

    public function test_existing_index_set_does_not_change_when_reading_or_applying(): void
    {
        $indexes = Schema::getIndexes('session_exercises');
        $this->workout();
        $this->workout();
        $this->apply($this->suggestion())->assertSessionHasNoErrors();
        $this->assertSame($indexes, Schema::getIndexes('session_exercises'));
    }

    public function test_ambiguous_frozen_prescription_does_not_create_a_historical_suggestion(): void
    {
        $this->workout(null, ['planned_reps_text' => 'AMRAP']);
        $this->workout(null, ['planned_reps_text' => 'AMRAP']);
        $key = $this->template->library_key;
        $this->template->delete();
        $this->assertNull($this->progression->detail($this->user, $key, 'reps')['suggestion']);
    }

    public function test_stale_apply_after_all_history_is_reopened_redirects_to_list_with_explanation(): void
    {
        $this->workout();
        $this->workout();
        $result = $this->suggestion();
        $this->user->scheduleEntries()->update(['status' => 'planned']);
        $this->apply($result)->assertRedirect('/progress/exercises')->assertSessionHasErrors('progression');
        $this->get('/progress/exercises')->assertOk()->assertInertia(fn (Assert $p) => $p->where('trackedCount', 0)->has('errors.progression'));
    }

    public function test_stale_apply_after_identity_change_refreshes_original_history_without_obsolete_selection(): void
    {
        $this->workout();
        $this->workout();
        $result = $this->suggestion();
        $key = $this->template->library_key;
        $this->template->update(['library_key' => 'custom:changed']);
        $response = $this->patch("/training-exercises/{$this->template->id}/progression", [
            'token' => $result['token'], 'value' => '82.5', 'exercise' => $key, 'mode' => 'reps',
        ])->assertSessionHasErrors('progression');
        $this->get($response->headers->get('Location'))->assertOk()->assertInertia(fn (Assert $p) => $p->where('progression.selected_id', null)->where('progression.suggestion.historical', true));
    }

    public static function prescriptions(): array
    {
        return [
            ['reps', '8', ['min' => 8, 'max' => 8]], ['reps', '8-10', ['min' => 8, 'max' => 10]],
            ['reps', '8 – 10', ['min' => 8, 'max' => 10]], ['reps', '10-12 reps', ['min' => 10, 'max' => 12]],
            ['reps', '10000', ['min' => 10000, 'max' => 10000]], ['reps', '10-8', null], ['reps', '0', null],
            ['reps', '-2', null], ['reps', '3x10', null], ['reps', '8+2', null], ['reps', 'AMRAP', null],
            ['reps', 'Until failure', null], ['reps', '8-10 or failure', null], ['reps', '10 with 3010 tempo', null],
            ['reps', '10001', null], ['reps', '999999999999999999999999999999', null], ['reps', '1:30', null],
            ['duration', '30 sec', 30], ['duration', '45 seconds', 45], ['duration', '1 min', 60],
            ['duration', '2 min', 120], ['duration', '1:30', 90], ['duration', '1440:00', 86400],
            ['duration', '86401 sec', null], ['duration', '1:60', null], ['duration', '0 sec', null],
            ['duration', '-30 sec', null], ['duration', '45 sec each side', null], ['duration', '30', null],
        ];
    }

    #[DataProvider('prescriptions')]
    public function test_prescription_parser_is_strict_and_bounded(string $mode, string $text, mixed $expected): void
    {
        $this->assertSame($expected, app(ProgressionPrescription::class)->$mode($text));
    }
}
