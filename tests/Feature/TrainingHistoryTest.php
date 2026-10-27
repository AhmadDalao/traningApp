<?php

namespace Tests\Feature;

use App\Models\ScheduleEntry;
use App\Models\TrainingDay;
use App\Models\TrainingPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TrainingHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private TrainingPlan $plan;

    private TrainingDay $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 10, 26)->setTime(12, 0));
        Storage::fake('public');
        $this->user = User::factory()->create();
        $this->plan = $this->user->trainingPlans()->create(['name' => 'Original plan']);
        $this->day = $this->plan->trainingDays()->create(['title' => 'Leg Day', 'type' => 'training', 'focus_area' => 'Legs']);
        $this->day->exercises()->create(['name' => 'Back Squat', 'sets' => 4, 'reps' => '8-10', 'target_weight' => 80, 'superset_group' => 'A', 'icon' => 'weight', 'position' => 0]);
        $this->day->exercises()->create(['name' => 'Leg Curl', 'sets' => 3, 'reps' => '12', 'superset_group' => 'A', 'position' => 1]);
    }

    private function entry(array $attributes = []): ScheduleEntry
    {
        return $this->user->scheduleEntries()->create([...['training_day_id' => $this->day->id, 'title' => 'Leg Day', 'entry_type' => 'training', 'focus_area' => 'Legs', 'scheduled_date' => '2026-10-26', 'status' => 'planned'], ...$attributes]);
    }

    private function complete(ScheduleEntry $entry): ScheduleEntry
    {
        $this->actingAs($this->user)->from('/dashboard')->patch("/schedule-entries/{$entry->id}/status", ['status' => 'completed'])->assertSessionHasNoErrors()->assertRedirect('/dashboard');

        return $entry->fresh();
    }

    public function test_target_weight_can_be_saved_omitted_zero_and_cleared(): void
    {
        $payload = ['title' => 'New day', 'type' => 'training', 'exercises' => [
            ['name' => 'Squat', 'sets' => 4, 'reps' => '8-10', 'target_weight' => '82.50'],
            ['name' => 'Plank', 'sets' => 3, 'reps' => '30 seconds'],
            ['name' => 'Bodyweight', 'sets' => 3, 'reps' => 'AMRAP', 'target_weight' => 0],
        ]];
        $this->actingAs($this->user)->post("/training-plans/{$this->plan->id}/days", $payload)->assertSessionHasNoErrors();
        $day = $this->plan->trainingDays()->where('title', 'New day')->firstOrFail();
        $this->assertSame(['82.50', null, '0.00'], $day->exercises->pluck('target_weight')->all());
        $payload['exercises'] = $day->exercises->map(fn ($exercise) => [...$exercise->only('id', 'name', 'sets', 'reps'), 'target_weight' => null])->all();
        $this->put("/training-days/{$day->id}", $payload)->assertSessionHasNoErrors();
        $this->assertSame([null, null, null], $day->fresh()->exercises->pluck('target_weight')->all());
    }

    public function test_invalid_target_weights_do_not_partially_save_a_day(): void
    {
        $this->actingAs($this->user);
        foreach ([-1, 'heavy', '80.123', 10000] as $weight) {
            $this->post("/training-plans/{$this->plan->id}/days", ['title' => 'Invalid', 'type' => 'training', 'exercises' => [['name' => 'Squat', 'sets' => 3, 'reps' => '8', 'target_weight' => $weight]]])->assertSessionHasErrors('exercises.0.target_weight');
        }
        $this->assertDatabaseCount('training_days', 1);
    }

    public function test_optional_time_saves_and_multiple_sessions_can_share_a_date(): void
    {
        $this->actingAs($this->user);
        foreach ([null, '07:15', '19:30'] as $time) {
            $this->post('/schedule-entries', ['entry_type' => 'training', 'training_day_id' => $this->day->id, 'scheduled_date' => '2026-10-26', 'scheduled_time' => $time])->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('schedule_entries', 3);
        $this->assertSame('planned', ScheduleEntry::first()->status);
        $this->assertNull(ScheduleEntry::first()->scheduled_time);
        $this->assertStringStartsWith('07:15', ScheduleEntry::orderBy('id')->skip(1)->first()->scheduled_time);
        $this->post('/schedule-entries', ['entry_type' => 'rest', 'scheduled_date' => '2026-10-26', 'scheduled_time' => '25:01'])->assertSessionHasErrors('scheduled_time');
    }

    public function test_completion_captures_the_workout_without_requiring_actual_weights(): void
    {
        $entry = $this->complete($this->entry());
        $this->assertTrue($entry->completed_at->equalTo(now()));
        $this->assertSame('Leg Day', $entry->workout_snapshot['title']);
        $this->assertSame('Original plan', $entry->workout_snapshot['training_plan_name']);
        $this->assertSame('80.00', $entry->workout_snapshot['exercises'][0]['target_weight']);
        $this->assertSame('A', $entry->workout_snapshot['exercises'][1]['superset_group']);
        $this->assertNull($entry->workout_snapshot['exercises'][0]['actual_weight']);
        $this->assertNull($entry->workout_snapshot['exercises'][1]['target_weight']);
        $snapshot = $entry->workout_snapshot;
        $completed = $entry->completed_at;
        $this->travel(10)->minutes();
        $again = $this->complete($entry);
        $this->assertSame($snapshot, $again->workout_snapshot);
        $this->assertTrue($completed->equalTo($again->completed_at));
    }

    public function test_snapshot_and_its_thumbnail_survive_template_edits_and_plan_deletion(): void
    {
        Storage::disk('public')->put('exercise-thumbnails/original.jpg', 'original image');
        $this->day->exercises()->first()->update(['thumbnail_path' => 'exercise-thumbnails/original.jpg']);
        $entry = $this->complete($this->entry());
        $snapshot = $entry->workout_snapshot;
        $copy = $snapshot['exercises'][0]['thumbnail_path'];
        $this->assertNotSame('exercise-thumbnails/original.jpg', $copy);
        Storage::disk('public')->assertExists($copy);
        $this->put("/training-days/{$this->day->id}", ['title' => 'Changed', 'type' => 'training', 'focus_area' => 'Arms', 'exercises' => [['name' => 'New exercise', 'sets' => 1, 'reps' => '20', 'target_weight' => 5]]])->assertSessionHasNoErrors();
        $this->assertSame($snapshot, $entry->fresh()->workout_snapshot);
        Storage::disk('public')->assertMissing('exercise-thumbnails/original.jpg');
        $this->delete("/training-plans/{$this->plan->id}")->assertRedirect();
        $this->assertNull($entry->fresh()->training_day_id);
        $this->get("/training-history/{$entry->id}")->assertInertia(fn (Assert $page) => $page->where('history', true)->where('workout.training_plan_name', 'Original plan')->where('workout.exercises.0.name', 'Back Squat')->where('workout.exercises.0.target_weight', '80.00')->where('workout.exercises.0.thumbnail_url', Storage::disk('public')->url($copy)));
        Storage::disk('public')->assertExists($copy);
        $this->delete("/schedule-entries/{$entry->id}")->assertRedirect('/calendar');
        Storage::disk('public')->assertMissing($copy);
    }

    public function test_status_reversals_keep_the_saved_workout_and_weights_but_clear_completion_time(): void
    {
        $entry = $this->complete($this->entry());
        $this->patch("/schedule-entries/{$entry->id}/weights", ['weights' => [['index' => 0, 'actual_weight' => 85]]])->assertSessionHasNoErrors();
        $snapshot = $entry->fresh()->workout_snapshot;
        foreach (['planned', 'skipped'] as $status) {
            $this->patch("/schedule-entries/{$entry->id}/status", ['status' => $status])->assertSessionHasNoErrors();
            $this->assertNull($entry->fresh()->completed_at);
            $this->assertSame($snapshot, $entry->fresh()->workout_snapshot);
        }
        $this->day->exercises()->update(['name' => 'Changed later', 'target_weight' => 100]);
        $this->travel(1)->hours();
        $entry = $this->complete($entry);
        $this->assertTrue($entry->completed_at->equalTo(now()));
        $this->assertSame($snapshot, $entry->workout_snapshot);
    }

    public function test_actual_weights_can_be_partial_zero_or_cleared_without_changing_targets(): void
    {
        $entry = $this->complete($this->entry());
        $this->patch("/schedule-entries/{$entry->id}/weights", ['weights' => [['index' => 0, 'actual_weight' => '85.25']]])->assertSessionHasNoErrors();
        $this->assertSame('85.25', $entry->fresh()->workout_snapshot['exercises'][0]['actual_weight']);
        $this->assertNull($entry->fresh()->workout_snapshot['exercises'][1]['actual_weight']);
        $this->assertSame('80.00', $this->day->exercises()->first()->target_weight);
        $this->patch("/schedule-entries/{$entry->id}/weights", ['weights' => [['index' => 0, 'actual_weight' => null], ['index' => 1, 'actual_weight' => 0]]])->assertSessionHasNoErrors();
        $this->assertNull($entry->fresh()->workout_snapshot['exercises'][0]['actual_weight']);
        $this->assertSame('0.00', $entry->fresh()->workout_snapshot['exercises'][1]['actual_weight']);
        $this->get('/training-history')->assertInertia(fn (Assert $page) => $page->where('entries.data.0.weights_logged', 1)->where('entries.data.0.scheduled_date', '2026-10-26'));
    }

    public function test_actual_weight_validation_rejects_invalid_values_indexes_and_snapshot_fields(): void
    {
        $entry = $this->complete($this->entry());
        $snapshot = $entry->workout_snapshot;
        foreach ([-1, 'heavy', '85.123', 10000] as $weight) {
            $this->patch("/schedule-entries/{$entry->id}/weights", ['weights' => [['index' => 0, 'actual_weight' => $weight]]])->assertSessionHasErrors('weights.0.actual_weight');
        }
        $this->patch("/schedule-entries/{$entry->id}/weights", ['weights' => [['index' => 99, 'actual_weight' => 20]]])->assertSessionHasErrors('weights.0.index');
        $this->patch("/schedule-entries/{$entry->id}/weights", ['weights' => [['index' => 0, 'actual_weight' => 20], ['index' => 0, 'actual_weight' => 30]]])->assertSessionHasErrors('weights.0.index');
        $this->patch("/schedule-entries/{$entry->id}/weights", ['weights' => [['index' => 0, 'actual_weight' => 20, 'name' => 'Forged']]])->assertSessionHasErrors('weights.0');
        $this->assertSame($snapshot, $entry->fresh()->workout_snapshot);
    }

    public function test_weights_cannot_be_recorded_before_completion_for_rest_or_legacy_sessions(): void
    {
        $this->actingAs($this->user);
        foreach ([['status' => 'planned'], ['status' => 'skipped'], ['status' => 'completed'], ['entry_type' => 'rest', 'status' => 'completed']] as $attributes) {
            $entry = $this->entry($attributes);
            $this->patch("/schedule-entries/{$entry->id}/weights", ['weights' => [['index' => 0, 'actual_weight' => 80]]])->assertSessionHasErrors('weights');
            $this->assertNull($entry->fresh()->workout_snapshot);
        }
    }

    public function test_history_includes_past_statuses_and_rest_but_excludes_future_dates_and_times(): void
    {
        foreach (['planned', 'completed', 'skipped'] as $status) {
            $this->entry(['status' => $status, 'scheduled_date' => '2026-10-25']);
        }
        $this->entry(['entry_type' => 'rest', 'status' => 'completed']);
        $this->entry(['scheduled_time' => '11:59']);
        $future = $this->entry(['scheduled_time' => '12:01']);
        $this->entry(['status' => 'completed', 'scheduled_date' => '2026-10-27']);
        $other = User::factory()->create();
        $other->scheduleEntries()->create(['title' => 'Other user', 'entry_type' => 'rest', 'scheduled_date' => '2026-10-25', 'status' => 'completed']);
        $this->actingAs($this->user)->get('/training-history')->assertInertia(fn (Assert $page) => $page->component('TrainingHistory/Index')->has('entries.data', 5)->where('summary', ['completed' => 1, 'skipped' => 1, 'rest' => 1, 'total' => 5]));
        $this->get("/training-history/{$future->id}")->assertNotFound();
    }

    public function test_history_filters_status_period_type_and_owned_plan_with_consistent_summary_counts(): void
    {
        $completed = $this->complete($this->entry());
        $this->entry(['status' => 'skipped']);
        $this->entry(['entry_type' => 'rest', 'status' => 'completed']);
        $this->entry(['scheduled_date' => '2026-09-01', 'status' => 'completed']);
        $this->get('/training-history?status=completed&period=this_month&plan='.$this->plan->id)->assertInertia(fn (Assert $page) => $page->has('entries.data', 1)->where('entries.data.0.id', $completed->id)->where('summary.total', 3)->where('summary.completed', 1));
        $this->get('/training-history?status=skipped')->assertInertia(fn (Assert $page) => $page->has('entries.data', 1)->where('entries.data.0.status', 'skipped'));
        $this->get('/training-history?type=rest')->assertInertia(fn (Assert $page) => $page->has('entries.data', 1)->where('entries.data.0.entry_type', 'rest')->where('summary', ['completed' => 0, 'skipped' => 0, 'rest' => 1, 'total' => 1]));
        $this->get('/training-history?type=training&period=this_month')->assertInertia(fn (Assert $page) => $page->has('entries.data', 2)->where('summary.rest', 0)->where('summary.total', 2));
        $this->get('/training-history?status=planned')->assertInertia(fn (Assert $page) => $page->has('entries.data', 0));
        $this->get('/training-history?period=custom&from=2026-09-01&to=2026-09-30')->assertInertia(fn (Assert $page) => $page->has('entries.data', 1)->where('summary.total', 1));
    }

    public function test_history_is_paginated_and_keeps_filters(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->entry(['status' => 'skipped']);
        }
        $query = 'status=skipped&period=custom&from=2026-10-01&to=2026-10-26&type=training&per_page=10&plan='.$this->plan->id;
        $this->actingAs($this->user)->get('/training-history?'.$query)->assertInertia(fn (Assert $page) => $page->has('entries.data', 10)->where('entries.total', 25)->where('entries.next_page_url', function ($url) {
            parse_str(parse_url($url, PHP_URL_QUERY), $params);

            return $params === ['status' => 'skipped', 'period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-26', 'type' => 'training', 'per_page' => '10', 'plan' => (string) $this->plan->id, 'page' => '2'];
        }));
        $this->get('/training-history?'.$query.'&page=3')->assertInertia(fn (Assert $page) => $page->has('entries.data', 5));
    }

    public function test_history_date_presets_include_both_boundaries_and_exclude_future_sessions(): void
    {
        foreach (['2026-09-26', '2026-09-27', '2026-09-30', '2026-10-01', '2026-10-19', '2026-10-20', '2026-10-25', '2026-10-26', '2026-10-27'] as $date) {
            $this->entry(['scheduled_date' => $date, 'status' => 'completed']);
        }
        $this->entry(['scheduled_time' => '12:01', 'status' => 'completed']);
        $this->actingAs($this->user);
        foreach (['today' => 1, 'yesterday' => 1, 'last_7_days' => 3, 'last_30_days' => 7, 'this_month' => 5, 'all' => 8] as $period => $count) {
            $this->get('/training-history?period='.$period)->assertInertia(fn (Assert $page) => $page->has('entries.data', $count)->where('summary.total', $count));
        }
        $this->get('/training-history?period=custom&from=2026-10-20&to=2026-10-25')->assertInertia(fn (Assert $page) => $page->has('entries.data', 2)->where('entries.data.0.scheduled_date', '2026-10-25')->where('entries.data.1.scheduled_date', '2026-10-20'));
        $this->get('/training-history?period=custom&from=2026-10-26&to=2026-11-01')->assertInertia(fn (Assert $page) => $page->has('entries.data', 1));
        $this->get('/training-history?period=today&from=bad&to=bad')->assertSessionHasNoErrors()->assertInertia(fn (Assert $page) => $page->has('entries.data', 1));
    }

    public function test_history_supports_every_page_size_and_all_including_empty_results(): void
    {
        for ($i = 0; $i < 105; $i++) {
            $this->entry();
        }
        $this->actingAs($this->user)->get('/training-history')->assertInertia(fn (Assert $page) => $page->has('entries.data', 10)->where('filters.per_page', '10'));
        foreach ([10, 25, 50, 100] as $size) {
            $this->get('/training-history?per_page='.$size)->assertInertia(fn (Assert $page) => $page->has('entries.data', $size)->where('entries.total', 105)->where('entries.per_page', $size));
        }
        $this->get('/training-history?per_page=all&page=3')->assertInertia(fn (Assert $page) => $page->has('entries.data', 105)->where('entries.last_page', 1)->where('entries.current_page', 1)->where('entries.next_page_url', null));
        $this->get('/training-history?per_page=all&type=rest')->assertInertia(fn (Assert $page) => $page->has('entries.data', 0)->where('entries.total', 0));
    }

    public function test_history_rejects_invalid_filter_values_and_incomplete_or_reversed_custom_ranges(): void
    {
        $this->actingAs($this->user);
        foreach ([
            ['period=custom', ['from', 'to']],
            ['period=custom&from=2026-10-26&to=2026-10-01', ['to']],
            ['period=custom&from=2026-02-30&to=2026-10-26', ['from']],
            ['period=invalid', ['period']],
            ['type=invalid', ['type']],
            ['per_page=0', ['per_page']],
            ['per_page=1000', ['per_page']],
        ] as [$query, $errors]) {
            $this->get('/training-history?'.$query)->assertSessionHasErrors($errors);
        }
    }

    public function test_dashboard_lists_all_upcoming_sessions_in_the_next_seven_days_only(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->entry(['scheduled_date' => '2026-10-27']);
        }
        $this->entry(['scheduled_date' => '2026-11-02']);
        $this->entry(['scheduled_date' => '2026-11-03']);
        $this->entry();
        User::factory()->create()->scheduleEntries()->create(['title' => 'Private', 'entry_type' => 'rest', 'scheduled_date' => '2026-10-27', 'status' => 'planned']);
        $this->actingAs($this->user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->has('upcomingEntries', 13)->where('stats.upcoming', 13)->where('upcomingEntries.12.scheduled_date', '2026-11-02'));
    }

    public function test_history_and_weight_updates_enforce_authentication_and_ownership(): void
    {
        $entry = $this->entry();
        $this->get('/training-history')->assertRedirect('/login');
        $this->get("/training-history/{$entry->id}")->assertRedirect('/login');
        $this->patch("/schedule-entries/{$entry->id}/weights", ['weights' => []])->assertRedirect('/login');
        $this->actingAs(User::factory()->create());
        $this->get('/training-history')->assertInertia(fn (Assert $page) => $page->has('entries.data', 0)->where('summary.total', 0));
        $this->get("/training-history/{$entry->id}")->assertForbidden();
        $this->patch("/schedule-entries/{$entry->id}/weights", ['weights' => [['index' => 0, 'actual_weight' => 99]]])->assertForbidden();
        $this->get('/training-history?plan='.$this->plan->id)->assertSessionHasErrors('plan');
    }

    public function test_snapshot_is_used_after_training_day_deletion_and_still_matches_plan_filter(): void
    {
        $entry = $this->complete($this->entry());
        $this->delete("/training-days/{$this->day->id}")->assertRedirect();
        $this->get('/training-history?plan='.$this->plan->id)->assertInertia(fn (Assert $page) => $page->has('entries.data', 1)->where('entries.data.0.exercises_count', 2));
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('todayEntries.0.exercises_count', 2));
        $this->get("/schedule-entries/{$entry->id}")->assertInertia(fn (Assert $page) => $page->has('workout.exercises', 2));
    }

    public function test_recorded_session_metadata_can_change_without_replacing_its_workout(): void
    {
        $entry = $this->complete($this->entry());
        $snapshot = $entry->workout_snapshot;
        $payload = ['training_day_id' => $this->day->id, 'entry_type' => 'training', 'title' => 'Morning legs', 'scheduled_date' => '2026-10-25', 'scheduled_time' => '08:30', 'notes' => 'Felt strong'];
        $this->put("/schedule-entries/{$entry->id}", $payload)->assertSessionHasNoErrors();
        $this->assertSame('Morning legs', $entry->fresh()->title);
        $this->assertSame($snapshot, $entry->fresh()->workout_snapshot);
        $otherDay = $this->plan->trainingDays()->create(['title' => 'Arms', 'type' => 'training']);
        $this->put("/schedule-entries/{$entry->id}", [...$payload, 'training_day_id' => $otherDay->id])->assertSessionHasErrors('training_day_id');
        $this->put("/schedule-entries/{$entry->id}", [...$payload, 'entry_type' => 'rest', 'training_day_id' => null])->assertSessionHasErrors('training_day_id');
        $this->assertSame($snapshot, $entry->fresh()->workout_snapshot);
    }

    public function test_legacy_completed_sessions_do_not_present_current_exercises_as_historical_facts(): void
    {
        $entry = $this->entry(['status' => 'completed']);
        $this->actingAs($this->user)->get("/training-history/{$entry->id}")->assertInertia(fn (Assert $page) => $page->where('workout', null)->where('entry.completed_at', null)->where('entry.workout_snapshot', null));
        $this->get('/training-history')->assertInertia(fn (Assert $page) => $page->where('entries.data.0.snapshot_available', false)->where('entries.data.0.exercises_count', 0));
    }

    public function test_rest_days_do_not_require_weights_and_never_count_as_completed_workouts(): void
    {
        $entry = $this->complete($this->entry(['entry_type' => 'rest', 'training_day_id' => null]));
        $this->assertSame([], $entry->workout_snapshot['exercises']);
        $this->get('/training-history')->assertInertia(fn (Assert $page) => $page->where('summary.completed', 0)->where('summary.rest', 1));
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('stats.completed', 0));
        $this->get("/training-history/{$entry->id}")->assertOk();
    }

    public function test_completion_after_template_deletion_preserves_session_without_inventing_exercises(): void
    {
        $entry = $this->entry();
        $this->actingAs($this->user)->delete("/training-days/{$this->day->id}");
        $entry = $this->complete($entry);
        $this->assertFalse($entry->workout_snapshot['template_available']);
        $this->assertSame([], $entry->workout_snapshot['exercises']);
        $this->assertNotNull($entry->completed_at);
    }
}
