<?php

namespace Tests\Feature;

use App\Models\ExerciseLibraryItem;
use App\Models\ScheduleEntry;
use App\Models\User;
use App\Services\ExerciseProgress;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExerciseProgressTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ExerciseProgress $progress;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        $this->progress = app(ExerciseProgress::class);
    }

    private function workout(array $sets, array $exercise = [], array $session = [], ?User $user = null): ScheduleEntry
    {
        $entry = ($user ?? $this->user)->scheduleEntries()->create([
            'title' => 'Recorded workout', 'entry_type' => 'training', 'status' => 'completed',
            'scheduled_date' => today()->toDateString(), 'completed_at' => now(), ...$session,
        ]);
        $movement = $entry->sessionExercises()->create([
            'source_exercise_key' => 'builtin:Barbell_Full_Squat', 'name' => 'Squat',
            'tracking_mode' => 'reps', 'planned_sets' => count($sets), 'planned_reps_text' => '100',
            'planned_weight_kg' => 999, 'position' => 0, 'status' => 'completed', ...$exercise,
        ]);
        foreach ($sets as $i => $set) {
            $movement->sets()->create([
                'set_number' => $i + 1, 'planned_reps_text' => '100', 'planned_weight_kg' => 999,
                'completed_at' => now(), ...$set,
            ]);
        }

        return $entry;
    }

    private function metrics(?string $key = null): array
    {
        return collect($this->progress->workouts($this->user, $key)->first() ?? [])->only(['heaviest_weight', 'best_set', 'weighted_volume', 'max_reps', 'total_reps', 'longest_duration', 'total_duration'])->all() + array_fill_keys(['heaviest_weight', 'best_set', 'weighted_volume', 'max_reps', 'total_reps', 'longest_duration', 'total_duration'], null);
    }

    public function test_zero_and_missing_weights_count_reps_without_weighted_metrics(): void
    {
        $this->workout([
            ['actual_reps' => 20, 'actual_weight_kg' => 0],
            ['actual_reps' => 18, 'actual_weight_kg' => null],
            ['actual_reps' => 15, 'actual_weight_kg' => 0],
        ]);
        $metrics = $this->metrics();
        $this->assertSame(20, $metrics['max_reps']);
        $this->assertSame(53, $metrics['total_reps']);
        foreach (['heaviest_weight', 'best_set', 'weighted_volume', 'longest_duration', 'total_duration'] as $field) {
            $this->assertNull($metrics[$field], $field);
        }
    }

    public function test_only_positive_actual_weights_contribute_to_weighted_metrics(): void
    {
        $this->workout([
            ['actual_reps' => 10, 'actual_weight_kg' => 80],
            ['actual_reps' => 9, 'actual_weight_kg' => 80],
            ['actual_reps' => 8, 'actual_weight_kg' => 85],
            ['actual_reps' => 8, 'actual_weight_kg' => 85],
            ['actual_reps' => 25, 'actual_weight_kg' => 0],
            ['actual_reps' => 20, 'actual_weight_kg' => null],
        ]);
        $metrics = $this->metrics();
        $this->assertSame(85.0, $metrics['heaviest_weight']);
        $this->assertSame(85.0, $metrics['best_set']['weight']);
        $this->assertSame(8, $metrics['best_set']['reps']);
        $this->assertSame(2880.0, $metrics['weighted_volume']);
        $this->assertSame(25, $metrics['max_reps']);
        $this->assertSame(80, $metrics['total_reps']);
    }

    public function test_best_set_prefers_heaviest_weight_then_highest_reps_and_preserves_decimals(): void
    {
        $this->workout([
            ['actual_reps' => 15, 'actual_weight_kg' => 80],
            ['actual_reps' => 4, 'actual_weight_kg' => 82.75],
            ['actual_reps' => 6, 'actual_weight_kg' => 82.75],
        ]);
        $metrics = $this->metrics();
        $this->assertSame(82.75, $metrics['best_set']['weight']);
        $this->assertSame(6, $metrics['best_set']['reps']);
        $this->assertSame(2027.5, $metrics['weighted_volume']);
    }

    public function test_duration_results_count_without_fabricated_reps_or_volume(): void
    {
        $this->workout([
            ['actual_duration_seconds' => 45, 'actual_weight_kg' => null],
            ['actual_duration_seconds' => 60, 'actual_weight_kg' => 0],
            ['actual_duration_seconds' => 75, 'actual_weight_kg' => 10],
        ], ['tracking_mode' => 'duration']);
        $metrics = $this->metrics();
        $this->assertSame(75, $metrics['longest_duration']);
        $this->assertSame(180, $metrics['total_duration']);
        $this->assertNull($metrics['total_reps']);
        $this->assertNull($metrics['weighted_volume']);
    }

    public static function excludedWork(): array
    {
        return [
            'incomplete set' => [['actual_reps' => 10, 'actual_weight_kg' => 80, 'completed_at' => null], [], []],
            'missing actual reps' => [['actual_weight_kg' => 80], [], []],
            'missing actual duration' => [['actual_reps' => 10], ['tracking_mode' => 'duration'], []],
            'skipped exercise' => [['actual_reps' => 10], ['status' => 'skipped'], []],
            'planned session' => [['actual_reps' => 10], [], ['status' => 'planned']],
            'skipped session' => [['actual_reps' => 10], [], ['status' => 'skipped']],
            'rest session' => [['actual_reps' => 10], [], ['entry_type' => 'rest']],
            'no identity' => [['actual_reps' => 10], ['source_exercise_key' => null], []],
            'empty identity' => [['actual_reps' => 10], ['source_exercise_key' => ''], []],
        ];
    }

    #[DataProvider('excludedWork')]
    public function test_only_eligible_completed_actual_results_are_read(array $set, array $exercise, array $session): void
    {
        $this->workout([$set], $exercise, $session);
        $this->assertSame(0, $this->progress->query($this->user)->count());
        $this->assertSame(array_fill_keys(['heaviest_weight', 'best_set', 'weighted_volume', 'max_reps', 'total_reps', 'longest_duration', 'total_duration'], null), $this->metrics());
    }

    public function test_partial_exercise_keeps_its_completed_sets_when_workout_is_completed(): void
    {
        $this->workout([
            ['actual_reps' => 10], ['actual_reps' => 25, 'completed_at' => null],
        ], ['status' => 'active']);
        $this->assertSame(10, $this->metrics()['total_reps']);
    }

    public function test_incomplete_heavier_sets_and_weight_without_reps_cannot_inflate_records(): void
    {
        $this->workout([
            ['actual_reps' => 10, 'actual_weight_kg' => 82.5],
            ['actual_reps' => 50, 'actual_weight_kg' => 150, 'completed_at' => null],
            ['actual_reps' => null, 'actual_weight_kg' => 200],
        ]);
        $metrics = $this->metrics();
        $this->assertSame(82.5, $metrics['heaviest_weight']);
        $this->assertSame(10, $metrics['max_reps']);
        $this->assertSame(10, $metrics['total_reps']);
        $this->assertSame(825.0, $metrics['weighted_volume']);
        $this->assertSame(10, $metrics['best_set']['reps']);
    }

    public function test_identity_and_ownership_are_used_instead_of_matching_names(): void
    {
        $this->workout([['actual_reps' => 10]], ['name' => 'Same name']);
        $this->workout([['actual_reps' => 20]], ['name' => 'Same name', 'source_exercise_key' => 'custom:17']);
        $this->workout([['actual_reps' => 50]], [], [], User::factory()->create());
        $this->assertSame(10, $this->metrics('builtin:Barbell_Full_Squat')['total_reps']);
        $this->assertSame(20, $this->metrics('custom:17')['total_reps']);
        $this->assertSame(2, $this->progress->query($this->user)->count());
    }

    public function test_current_session_status_controls_inclusion_after_reopening_and_recompletion(): void
    {
        $entry = $this->workout([['actual_reps' => 10, 'actual_weight_kg' => 80]]);
        $this->assertSame(80.0, $this->metrics()['heaviest_weight']);
        foreach (['planned', 'skipped'] as $status) {
            $this->patch("/schedule-entries/{$entry->id}/status", ['status' => $status])->assertSessionHasNoErrors();
            $this->assertNull($this->metrics()['heaviest_weight']);
        }
        $this->patch("/schedule-entries/{$entry->id}/status", ['status' => 'planned'])->assertSessionHasNoErrors();
        $this->post("/schedule-entries/{$entry->id}/workout/start")->assertSessionHasNoErrors();
        $set = $entry->sessionExercises()->first()->sets()->first();
        $this->patch("/session-sets/{$set->id}", ['actual_reps' => 12, 'actual_weight_kg' => 82.5])->assertSessionHasNoErrors();
        $this->assertNull($this->metrics()['heaviest_weight']);
        $this->post("/schedule-entries/{$entry->id}/workout/finish")->assertSessionHasNoErrors();
        $this->assertSame(82.5, $this->metrics()['heaviest_weight']);
        $this->assertSame(12, $this->metrics()['total_reps']);
    }

    public function test_legacy_snapshot_weights_are_not_converted_into_structured_progress(): void
    {
        $this->user->scheduleEntries()->create([
            'title' => 'Legacy', 'entry_type' => 'training', 'status' => 'completed', 'scheduled_date' => today(),
            'workout_snapshot' => ['version' => 1, 'exercises' => [['name' => 'Squat', 'actual_weight' => 999, 'sets' => 5, 'reps' => '10']]],
        ]);
        $this->assertSame(0, $this->progress->query($this->user)->count());
    }

    private function detail(string $key = 'builtin:Barbell_Full_Squat', string $mode = 'reps', string $range = 'all'): array
    {
        return $this->progress->detail($this->user, $key, $mode, $range);
    }

    public function test_one_point_per_workout_combines_duplicate_exercise_occurrences(): void
    {
        $entry = $this->workout([['actual_reps' => 10, 'actual_weight_kg' => 80]]);
        $repeat = $entry->sessionExercises()->create(['source_exercise_key' => 'builtin:Barbell_Full_Squat', 'name' => 'Renamed squat', 'tracking_mode' => 'reps', 'planned_sets' => 1, 'position' => 1, 'status' => 'completed']);
        $repeat->sets()->create(['set_number' => 1, 'actual_reps' => 8, 'actual_weight_kg' => 85, 'completed_at' => now()]);
        $detail = $this->detail();
        $this->assertSame(1, $detail['exercise']['workouts']);
        $this->assertCount(1, $detail['charts'][0]['points']);
        $this->assertSame(85.0, $detail['charts'][0]['points'][0]['value']);
        $this->assertSame(1480.0, $detail['charts'][1]['points'][0]['value']);
        $this->assertCount(2, $detail['history']->items()[0]['sets']);
    }

    public function test_all_time_records_use_best_workout_volume_not_sum_of_all_history(): void
    {
        $early = $this->workout([['actual_reps' => 10, 'actual_weight_kg' => 80], ['actual_reps' => 8, 'actual_weight_kg' => 80]], [], ['scheduled_date' => today()->subDays(120)]);
        $this->workout([['actual_reps' => 4, 'actual_weight_kg' => 100]]);
        $detail = $this->detail(range: '30');
        $records = collect($detail['records'])->keyBy('metric');
        $this->assertSame(1440.0, $records['weighted_volume']['value']);
        $this->assertSame($early->id, $records['weighted_volume']['session_id']);
        $this->assertSame(100.0, $records['heaviest_weight']['value']);
        $this->assertSame(100.0, $detail['exercise']['latest_weight']);
        $this->assertCount(1, $detail['charts'][0]['points']);
        $this->assertSame(2, $detail['exercise']['workouts']);
    }

    public function test_unweighted_workouts_are_omitted_from_weight_charts_but_keep_rep_history(): void
    {
        $this->workout([['actual_reps' => 10, 'actual_weight_kg' => 20]], [], ['scheduled_date' => today()->subDay()]);
        $latest = $this->workout([['actual_reps' => 30, 'actual_weight_kg' => 0], ['actual_reps' => 25, 'actual_weight_kg' => null]]);
        $detail = $this->detail();
        $this->assertNull($detail['exercise']['latest_weight']);
        $this->assertCount(1, $detail['charts'][0]['points']);
        $this->assertCount(1, $detail['charts'][1]['points']);
        $this->assertSame($latest->id, $detail['history']->items()[0]['session_id']);
        $this->assertSame(55, $detail['history']->items()[0]['total_reps']);
        $this->assertNull($detail['history']->items()[0]['weighted_volume']);
        $reps = collect($detail['records'])->firstWhere('metric', 'max_reps');
        $this->assertSame(30, $reps['value']);
        $this->assertNull($reps['weight']);
    }

    public function test_reps_only_chart_and_records_do_not_invent_weight(): void
    {
        $this->workout([['actual_reps' => 20, 'actual_weight_kg' => 0], ['actual_reps' => 18]], [], ['scheduled_date' => today()->subDay()]);
        $this->workout([['actual_reps' => 25], ['actual_reps' => 15]]);
        $detail = $this->detail();
        $this->assertSame(['max_reps', 'total_reps'], array_column($detail['records'], 'metric'));
        $this->assertSame('max_reps', $detail['charts'][0]['metric']);
        $this->assertSame([20, 25], $detail['charts'][0]['points']->pluck('value')->all());
        $this->assertFalse($detail['exercise']['weighted']);
        $this->assertSame(40, collect($detail['records'])->firstWhere('metric', 'total_reps')['value']);
    }

    public function test_duration_charts_ignore_incomplete_and_planned_values(): void
    {
        $this->workout([['actual_duration_seconds' => 45], ['actual_duration_seconds' => 60], ['actual_duration_seconds' => 999, 'completed_at' => null]], ['tracking_mode' => 'duration'], ['scheduled_date' => today()->subDay()]);
        $this->workout([['actual_duration_seconds' => 75], ['actual_duration_seconds' => 90]], ['tracking_mode' => 'duration']);
        $detail = $this->detail(mode: 'duration');
        $this->assertSame(['longest_duration', 'total_duration'], array_column($detail['records'], 'metric'));
        $this->assertSame([60, 90], $detail['charts'][0]['points']->pluck('value')->all());
        $this->assertSame(165, $detail['records'][1]['value']);
    }

    public function test_same_identity_with_different_tracking_modes_stays_separate(): void
    {
        $this->workout([['actual_reps' => 20]]);
        $this->workout([['actual_duration_seconds' => 90]], ['tracking_mode' => 'duration']);
        $this->assertSame(2, $this->progress->overview($this->user)['trackedCount']);
        $this->assertSame(20, $this->detail()['records'][0]['value']);
        $this->assertSame(90, $this->detail(mode: 'duration')['records'][0]['value']);
    }

    public function test_new_records_compare_strictly_against_prior_workouts_and_not_future_results(): void
    {
        $first = $this->workout([['actual_reps' => 5, 'actual_weight_kg' => 80]], [], ['scheduled_date' => today()->subDays(3)]);
        $equal = $this->workout([['actual_reps' => 5, 'actual_weight_kg' => 80]], [], ['scheduled_date' => today()->subDays(2)]);
        $reps = $this->workout([['actual_reps' => 6, 'actual_weight_kg' => 80]], [], ['scheduled_date' => today()->subDay()]);
        $weight = $this->workout([['actual_reps' => 6, 'actual_weight_kg' => 90]]);
        $summary = fn ($entry) => $this->progress->forSession($this->user, $entry->load('sessionExercises'));
        $this->assertSame([], $summary($first)['records']);
        $this->assertSame(['Squat'], $summary($first)['firstPerformances']);
        $this->assertSame([], $summary($equal)['records']);
        $repTypes = array_column($summary($reps)['records'], 'metric');
        $this->assertSame(['best_set', 'max_reps', 'weighted_volume'], $repTypes);
        $weightTypes = array_column($summary($weight)['records'], 'metric');
        $this->assertSame(['heaviest_weight', 'weighted_volume'], $weightTypes);
        $this->assertSame(90.0, $summary($weight)['records'][0]['value']);
    }

    public function test_rep_and_duration_records_and_equal_results(): void
    {
        $first = $this->workout([['actual_reps' => 20]], [], ['scheduled_date' => today()->subDay()]);
        $second = $this->workout([['actual_reps' => 25], ['actual_reps' => 20]]);
        $this->assertSame(['max_reps', 'total_reps'], array_column($this->progress->forSession($this->user, $second)['records'], 'metric'));
        $this->workout([['actual_duration_seconds' => 45]], ['tracking_mode' => 'duration'], ['scheduled_date' => today()->subDay()]);
        $timed = $this->workout([['actual_duration_seconds' => 90]], ['tracking_mode' => 'duration']);
        $this->assertSame(['longest_duration', 'total_duration'], array_column($this->progress->forSession($this->user, $timed)['records'], 'metric'));
    }

    public function test_same_day_workouts_follow_scheduled_time_then_id(): void
    {
        $late = $this->workout([['actual_reps' => 30]], [], ['scheduled_time' => '18:00']);
        $early = $this->workout([['actual_reps' => 20]], [], ['scheduled_time' => '08:00']);
        $tie = $this->workout([['actual_reps' => 22]], [], ['scheduled_time' => '08:00']);
        $points = $this->detail()['charts'][0]['points'];
        $this->assertSame([$early->id, $tie->id, $late->id], $points->pluck('session_id')->all());
        $this->assertSame([$late->id, $tie->id, $early->id], array_column($this->detail()['history']->items(), 'session_id'));
        $this->assertSame(['Squat'], $this->progress->forSession($this->user, $early)['firstPerformances']);
        $this->assertCount(2, $this->progress->forSession($this->user, $late)['records']);
    }

    public static function ranges(): array
    {
        return [['30', '2026-09-28'], ['90', '2026-07-30'], ['6m', '2026-04-27'], ['1y', '2025-10-27']];
    }

    #[DataProvider('ranges')]
    public function test_ranges_include_boundary_and_exclude_older_or_future_dates(string $range, string $boundary): void
    {
        $this->travelTo(now()->setDate(2026, 10, 27)->setTime(0, 5));
        $this->workout([['actual_reps' => 10]], [], ['scheduled_date' => Carbon::parse($boundary)->subDay()]);
        $included = $this->workout([['actual_reps' => 11]], [], ['scheduled_date' => $boundary]);
        $today = $this->workout([['actual_reps' => 12]]);
        $this->workout([['actual_reps' => 13]], [], ['scheduled_date' => today()->addDay()]);
        $detail = $this->detail(range: $range);
        $this->assertSame([$included->id, $today->id], $detail['charts'][0]['points']->pluck('session_id')->all());
        $this->assertSame(2, $detail['history']->total());
        $this->assertSame(4, $this->detail(range: 'all')['history']->total());
    }

    public function test_http_default_range_history_pagination_and_filtered_links(): void
    {
        for ($i = 12; $i >= 0; $i--) {
            $this->workout([['actual_reps' => 10 + $i]], [], ['scheduled_date' => today()->subDays($i)]);
        }
        $url = $this->progress->url('builtin:Barbell_Full_Squat', 'reps');
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component('ExerciseProgress/Show')->where('range', '90')->has('history.data', 10)->where('history.total', 13)->has('charts.0.points', 13));
        $this->get($url.'&range=all&page=2')->assertInertia(fn (Assert $page) => $page->has('history.data', 3)->where('history.current_page', 2)->where('history.prev_page_url', fn ($url) => str_contains($url, 'range=all') && str_contains($url, 'exercise=')));
    }

    public function test_search_pagination_and_recent_prs_are_owned_and_bounded(): void
    {
        for ($i = 0; $i < 14; $i++) {
            $this->workout([['actual_reps' => 10]], ['name' => 'Tracked '.$i, 'source_exercise_key' => 'custom:'.$i], ['scheduled_date' => today()->subDay()]);
            $this->workout([['actual_reps' => 11]], ['name' => 'Tracked '.$i, 'source_exercise_key' => 'custom:'.$i]);
        }
        $this->get('/progress/exercises')->assertInertia(fn (Assert $page) => $page->component('ExerciseProgress/Index')->has('exercises.data', 12)->where('exercises.total', 14)->has('recentRecords', 8));
        $this->get('/progress/exercises?page=2')->assertInertia(fn (Assert $page) => $page->has('exercises.data', 2));
        $this->get('/progress/exercises?search=Tracked%2013')->assertInertia(fn (Assert $page) => $page->has('exercises.data', 1)->where('exercises.data.0.name', 'Tracked 13'));
    }

    public function test_progress_routes_do_not_expose_other_users_and_require_authentication(): void
    {
        $other = User::factory()->create();
        $this->workout([['actual_reps' => 99, 'actual_weight_kg' => 100]], ['source_exercise_key' => 'custom:private'], [], $other);
        $this->get($this->progress->url('custom:private', 'reps'))->assertNotFound();
        $this->get('/progress/exercises')->assertInertia(fn (Assert $page) => $page->where('trackedCount', 0)->has('recentRecords', 0)->has('exercises.data', 0));
        auth()->logout();
        $this->get('/progress/exercises')->assertRedirect('/login');
    }

    public function test_invalid_filters_are_rejected_without_interpreting_keys_as_sql_or_paths(): void
    {
        foreach (['range' => 'forever', 'mode' => 'anything', 'page' => -1] as $field => $value) {
            $this->get('/progress/exercises?'.http_build_query([$field => $value]))->assertSessionHasErrors($field);
        }
        $this->get($this->progress->url("custom:' OR 1=1 -- / &", 'reps'))->assertNotFound();
    }

    public function test_preserved_identity_and_media_survive_template_and_custom_library_deletion(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('workout-thumbnails/kept.jpg', 'preserved');
        $plan = $this->user->trainingPlans()->create(['name' => 'Disposable template']);
        $day = $plan->trainingDays()->create(['title' => 'Day', 'type' => 'training']);
        $template = $day->exercises()->create(['name' => 'Custom squat', 'sets' => 1, 'reps' => '8', 'position' => 0]);
        $custom = ExerciseLibraryItem::create(['user_id' => $this->user->id, 'name' => 'Custom squat', 'icon' => 'dumbbell']);
        $key = 'custom:'.$custom->id;
        $entry = $this->workout([['actual_reps' => 10, 'actual_weight_kg' => 82.5]], ['source_exercise_key' => $key, 'source_training_exercise_id' => $template->id, 'thumbnail_path' => 'workout-thumbnails/kept.jpg'], ['training_day_id' => $day->id]);
        $plan->delete();
        $custom->delete();
        $detail = $this->detail($key);
        $this->assertSame(82.5, $detail['records'][0]['value']);
        $this->assertStringContainsString('workout-thumbnails/kept.jpg', $detail['exercise']['thumbnail_url']);
        $this->assertNull($entry->sessionExercises()->first()->source_training_exercise_id);
        Storage::disk('public')->assertExists('workout-thumbnails/kept.jpg');
    }

    public function test_status_reversal_removes_records_charts_recent_prs_and_workout_links(): void
    {
        $this->workout([['actual_reps' => 10, 'actual_weight_kg' => 80]], [], ['scheduled_date' => today()->subDay()]);
        $latest = $this->workout([['actual_reps' => 12, 'actual_weight_kg' => 90]]);
        $this->assertNotEmpty($this->progress->overview($this->user)['recentRecords']);
        $this->patch("/schedule-entries/{$latest->id}/status", ['status' => 'skipped'])->assertSessionHasNoErrors();
        $this->assertSame(80.0, $this->detail()['records'][0]['value']);
        $this->assertCount(1, $this->detail()['charts'][0]['points']);
        $this->assertCount(0, $this->progress->overview($this->user)['recentRecords']);
        $this->get("/schedule-entries/{$latest->id}")->assertInertia(fn (Assert $page) => $page->where('progressSummary', null));
        $this->patch("/schedule-entries/{$latest->id}/status", ['status' => 'completed'])->assertSessionHasNoErrors();
        $this->assertSame(90.0, $this->detail()['records'][0]['value']);
        $this->get("/schedule-entries/{$latest->id}")->assertInertia(fn (Assert $page) => $page->has('progressSummary.records', 3)->has('progressSummary.links', 1));
    }

    public function test_progress_adds_only_one_useful_identity_index(): void
    {
        // Re-running the additive migration must recognize the existing useful index.
        (require database_path('migrations/2026_10_27_000008_index_exercise_progress_identity.php'))->up();
        $indexes = collect(Schema::getIndexes('session_exercises'))->filter(fn ($index) => ($index['columns'][0] ?? null) === 'source_exercise_key');
        $this->assertCount(1, $indexes);
        $this->assertSame(['source_exercise_key'], $indexes->first()['columns']);
    }
}
