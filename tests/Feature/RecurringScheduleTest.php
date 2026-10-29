<?php

namespace Tests\Feature;

use App\Models\CoachTraineeRelationship;
use App\Models\ScheduleEntry;
use App\Models\TrainingPlan;
use App\Models\User;
use App\Services\PlanSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RecurringScheduleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private TrainingPlan $plan;

    private $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');
        $this->travelTo(now()->setDate(2026, 10, 28)->setTime(12, 0));
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        $this->plan = $this->user->trainingPlans()->create(['name' => 'Recurring strength', 'start_date' => '2026-11-01', 'schedule_mode' => 'ongoing']);
        $this->day = $this->plan->trainingDays()->create(['title' => 'Legs', 'type' => 'training', 'weekdays' => [1], 'default_time' => '18:00']);
    }

    private function activate(string $base = ''): void
    {
        $preview = $this->get($base.'/training-plans/'.$this->plan->id.'/schedule-preview')->assertOk()->viewData('page')['props']['preview'];
        $this->post($base.'/training-plans/'.$this->plan->id.'/schedule', ['signature' => $preview['signature'], 'confirmed' => true, 'action' => 'add'])->assertSessionHasNoErrors();
    }

    private function entry(string $date = '2026-11-02'): ScheduleEntry
    {
        return $this->user->scheduleEntries()->where('training_day_id', $this->day->id)->whereDate('scheduled_date', $date)->firstOrFail();
    }

    private function move(ScheduleEntry $entry, string $date, string $scope = 'one', string $base = ''): array
    {
        $data = ['scheduled_date' => $date, 'scheduled_time' => substr($entry->scheduled_time ?? '', 0, 5), 'scope' => $scope];
        $preview = $this->postJson($base.'/schedule-entries/'.$entry->id.'/reschedule-preview', $data)->assertOk()->json('preview');
        $this->postJson($base.'/schedule-entries/'.$entry->id.'/reschedule', [...$data, 'signature' => $preview['signature'], 'confirmed' => true])->assertOk()->assertJsonPath('saved', true);

        return $preview;
    }

    private function stop(string $date, string $base = ''): array
    {
        $url = $base.'/training-plans/'.$this->plan->id.'/stop-schedule';
        $preview = $this->postJson($url.'-preview', ['end_date' => $date])->assertOk()->json('preview');
        $this->postJson($url, ['end_date' => $date, 'signature' => $preview['signature'], 'confirmed' => true])->assertOk();

        return $preview;
    }

    public function test_ongoing_is_explicit_requires_start_and_never_uses_a_fake_end_date(): void
    {
        $this->post('/training-plans', ['name' => 'Ongoing', 'schedule_mode' => 'ongoing', 'start_date' => '2026-11-01', 'end_date' => '2099-12-31'])->assertSessionHasNoErrors();
        $this->assertNull(TrainingPlan::latest('id')->first()->end_date);
        $this->post('/training-plans', ['name' => 'Invalid', 'schedule_mode' => 'ongoing'])->assertSessionHasErrors('start_date');
        $this->post('/training-plans', ['name' => 'Fixed', 'start_date' => '2026-11-01'])->assertSessionHasErrors('end_date');
        $this->post('/training-plans', ['name' => 'Unscheduled'])->assertSessionHasNoErrors();
    }

    public function test_calendar_does_not_activate_drafts_and_activation_is_bounded_idempotent_and_advances(): void
    {
        $this->get('/calendar')->assertOk();
        $this->assertDatabaseCount('schedule_entries', 0);
        $this->activate();
        $this->assertTrue($this->plan->fresh()->schedule_enabled);
        $first = ScheduleEntry::orderBy('id')->get()->map->getAttributes()->all();
        $this->assertNotEmpty($first);
        $this->assertSame('2027-01-26', app(PlanSchedule::class)->horizon());
        $this->assertSame(0, ScheduleEntry::whereDate('scheduled_date', '>', '2027-01-26')->count());
        $this->assertSame(0, ScheduleEntry::whereDate('scheduled_date', '<', '2026-11-01')->count());
        $this->get('/calendar');
        $this->get('/training-plans/'.$this->plan->id.'/schedule-preview');
        $this->assertSame($first, ScheduleEntry::orderBy('id')->get()->map->getAttributes()->all());
        $this->travelTo(now()->addWeeks(2));
        $this->get('/calendar');
        $this->assertGreaterThan(count($first), ScheduleEntry::count());
        foreach ($first as $row) {
            $this->assertSame($row, ScheduleEntry::findOrFail($row['id'])->getAttributes());
        }
    }

    public function test_horizon_is_centralized_and_leap_year_and_month_boundaries_work(): void
    {
        config(['training.schedule_horizon_days' => 7]);
        $this->travelTo(now()->setDate(2028, 2, 25));
        $this->plan->update(['start_date' => '2028-02-25']);
        $this->day->update(['weekdays' => [0, 1, 2, 3, 4, 5, 6]]);
        $this->activate();
        $this->assertDatabaseHas('schedule_entries', ['scheduled_date' => '2028-02-29']);
        $this->assertSame(8, ScheduleEntry::count());
        $this->assertSame('2028-03-03', substr(ScheduleEntry::max('scheduled_date'), 0, 10));
    }

    public function test_calendar_rolling_refresh_does_not_load_unrelated_old_workout_history(): void
    {
        $this->activate();
        $history = [];
        for ($i = 0; $i < 300; $i++) {
            $history[] = ['user_id' => $this->user->id, 'training_day_id' => $this->day->id, 'title' => 'Old workout', 'entry_type' => 'training', 'status' => 'completed', 'scheduled_date' => '2020-01-01'];
        }
        DB::table('schedule_entries')->insert($history);
        $dispatcher = ScheduleEntry::getEventDispatcher();
        ScheduleEntry::setEventDispatcher(clone $dispatcher);
        $oldRows = 0;
        ScheduleEntry::retrieved(function ($entry) use (&$oldRows) {
            if ($entry->scheduled_date->year === 2020) {
                $oldRows++;
            }
        });
        try {
            $this->get('/calendar')->assertOk();
            $this->assertSame(0, $oldRows, 'Opening Calendar must not hydrate old workouts while extending the future window.');
            $this->assertSame(300, ScheduleEntry::whereYear('scheduled_date', 2020)->count());
        } finally {
            ScheduleEntry::setEventDispatcher($dispatcher);
        }
    }

    public function test_rolling_refresh_remembers_occurrences_moved_outside_its_visible_window(): void
    {
        $this->activate();
        // A previously moved occurrence may now be historical or beyond the horizon.
        $past = $this->entry('2026-11-02');
        $past->update(['scheduled_date' => '2026-09-01', 'schedule_managed' => false, 'status' => 'completed']);
        $later = $this->entry('2026-11-09');
        $later->update(['scheduled_date' => '2027-05-01', 'schedule_managed' => false]);
        $before = [$past->fresh()->getAttributes(), $later->fresh()->getAttributes()];
        app(PlanSchedule::class)->ensureWindow($this->user);
        app(PlanSchedule::class)->ensureWindow($this->user);
        $this->assertSame($before, [$past->fresh()->getAttributes(), $later->fresh()->getAttributes()]);
        $this->assertDatabaseMissing('schedule_entries', ['training_day_id' => $this->day->id, 'scheduled_date' => '2026-11-02']);
        $this->assertDatabaseMissing('schedule_entries', ['training_day_id' => $this->day->id, 'scheduled_date' => '2026-11-09']);
    }

    public function test_calendar_query_count_does_not_grow_per_existing_session(): void
    {
        $this->activate();
        $queries = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $this->get('/calendar')->assertOk();

                return count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
            }
        };
        $baseline = $queries();
        for ($i = 0; $i < 40; $i++) {
            $this->user->scheduleEntries()->create(['training_day_id' => $this->day->id, 'title' => 'Manual extra', 'entry_type' => 'rest', 'status' => 'planned', 'scheduled_date' => '2026-11-03']);
        }
        $this->assertLessThanOrEqual($baseline + 1, $queries());
    }

    public function test_fixed_period_still_ends_at_its_end_date_and_never_rolls(): void
    {
        $this->plan->update(['schedule_mode' => 'fixed', 'end_date' => '2026-11-16']);
        $this->activate();
        $this->assertDatabaseCount('schedule_entries', 3);
        $this->assertFalse($this->plan->fresh()->schedule_enabled);
        $this->get('/calendar');
        $this->assertDatabaseCount('schedule_entries', 3);
    }

    public function test_single_occurrence_retains_identity_and_survives_all_reconciliation_paths(): void
    {
        $this->activate();
        $entry = $this->entry();
        $key = $entry->plan_occurrence_key;
        $this->move($entry, '2026-11-03');
        $this->assertSame($key, $entry->fresh()->plan_occurrence_key);
        $this->assertFalse($entry->fresh()->schedule_managed);
        $this->assertSame([1], $this->day->fresh()->weekdays);
        $this->get('/calendar');
        $this->activate();
        $preview = app(PlanSchedule::class)->preview($this->plan->fresh());
        $this->post('/training-plans/'.$this->plan->id.'/schedule', ['signature' => $preview['signature'], 'action' => 'regenerate', 'confirmed' => true, 'remove_obsolete' => true])->assertSessionHasNoErrors();
        $this->assertSame('2026-11-03', $entry->fresh()->scheduled_date->toDateString());
        $this->assertDatabaseMissing('schedule_entries', ['scheduled_date' => '2026-11-02']);
        $this->assertNotNull($this->entry('2026-11-09'));
    }

    public function test_this_and_future_changes_only_one_weekday_stream_and_keeps_earlier_sessions(): void
    {
        $this->day->update(['weekdays' => [2, 6]]);
        $this->activate();
        $earlier = $this->entry('2026-11-03')->getAttributes();
        $saturdays = ScheduleEntry::get()->filter(fn ($entry) => $entry->scheduled_date->dayOfWeek === 6)->map->getAttributes()->all();
        $selected = $this->entry('2026-11-10');
        $preview = $this->move($selected, '2026-11-11', 'future');
        $this->assertNotEmpty($preview['add']);
        $this->assertNotEmpty($preview['remove']);
        $this->assertSame([3, 6], $this->day->fresh()->weekdays);
        $this->assertSame([2, 6], $this->day->fresh()->weekdaysOn('2026-11-09'));
        $this->assertSame([3, 6], $this->day->fresh()->weekdaysOn('2026-11-11'));
        $this->assertSame($earlier, ScheduleEntry::find($earlier['id'])->getAttributes());
        foreach ($saturdays as $row) {
            $this->assertSame($row, ScheduleEntry::find($row['id'])->getAttributes());
        }
        $this->get('/calendar');
        $this->assertDatabaseMissing('schedule_entries', ['scheduled_date' => '2026-11-17']);
        $this->assertNotNull($this->entry('2026-11-18'));
    }

    public function test_weekday_collision_normalizes_without_duplicate_rules_or_new_duplicate_occurrences(): void
    {
        $this->day->update(['weekdays' => [1, 2]]);
        $this->activate();
        $tuesday = $this->entry('2026-11-03')->id;
        $this->move($this->entry(), '2026-11-03', 'future');
        $this->assertSame([2], $this->day->fresh()->weekdays);
        $this->assertNotNull(ScheduleEntry::find($tuesday));
        // Two explicitly selected occurrences can share a date, but generation adds no third.
        $this->get('/calendar');
        $this->assertSame(2, ScheduleEntry::whereDate('scheduled_date', '2026-11-03')->count());
        $this->assertSame(1, ScheduleEntry::whereDate('scheduled_date', '2026-11-10')->count());
    }

    public function test_later_destination_delays_stream_and_earlier_destination_moves_only_the_selected_extra(): void
    {
        $this->activate();
        $this->move($this->entry(), '2026-11-17', 'future');
        $this->assertDatabaseMissing('schedule_entries', ['scheduled_date' => '2026-11-03']);
        $this->assertDatabaseMissing('schedule_entries', ['scheduled_date' => '2026-11-10']);
        $this->assertNotNull($this->entry('2026-11-24'));
        $this->move($this->entry('2026-11-24'), '2026-11-12', 'future');
        $this->assertNotNull($this->entry('2026-11-12'));
        $this->assertDatabaseMissing('schedule_entries', ['scheduled_date' => '2026-11-19']);
        $this->assertNotNull($this->entry('2026-11-26'));
    }

    public function test_future_change_does_not_move_history_active_saved_manual_or_unrelated_plan_sessions(): void
    {
        $this->activate();
        $protected = [];
        foreach (['2026-11-09' => ['status' => 'completed'], '2026-11-16' => ['status' => 'skipped'], '2026-11-23' => ['started_at' => now()], '2026-11-30' => ['workout_snapshot' => ['exercises' => []]], '2026-12-07' => ['schedule_managed' => false]] as $date => $fields) {
            $entry = $this->entry($date);
            $entry->update($fields);
            $protected[] = $entry->fresh()->getAttributes();
        }
        $manual = $this->user->scheduleEntries()->create(['training_day_id' => $this->day->id, 'title' => 'Extra Sunday', 'entry_type' => 'training', 'status' => 'planned', 'scheduled_date' => '2026-11-08']);
        $protected[] = $manual->fresh()->getAttributes();
        $other = $this->user->trainingPlans()->create(['name' => 'Other', 'start_date' => '2026-11-01', 'end_date' => '2026-11-30']);
        $other->trainingDays()->create(['title' => 'Other', 'type' => 'rest', 'weekdays' => [1]]);
        $before = $other->fresh()->getAttributes();
        $this->move($this->entry(), '2026-11-03', 'future');
        foreach ($protected as $row) {
            $this->assertSame($row, ScheduleEntry::find($row['id'])->getAttributes());
        }
        $this->assertSame($before, $other->fresh()->getAttributes());
    }

    public static function protectedStates(): array
    {
        return [['completed'], ['skipped'], ['started'], ['snapshot'], ['structured'], ['past'], ['untimed_today']];
    }

    #[DataProvider('protectedStates')]
    public function test_both_reschedule_endpoints_reject_protected_states(string $state): void
    {
        $this->activate();
        $entry = $this->entry();
        match ($state) {
            'completed','skipped' => $entry->update(['status' => $state]),
            'started' => $entry->update(['started_at' => now()]),
            'snapshot' => $entry->update(['workout_snapshot' => ['exercises' => []]]),
            'structured' => $entry->sessionExercises()->create(['name' => 'Saved', 'tracking_mode' => 'reps', 'planned_sets' => 1, 'position' => 0]),
            'past' => $entry->update(['scheduled_date' => '2026-10-27']),
            'untimed_today' => $entry->update(['scheduled_date' => '2026-10-28', 'scheduled_time' => null]),
        };
        $before = $entry->fresh()->getAttributes();
        foreach (['-preview', ''] as $suffix) {
            $this->postJson('/schedule-entries/'.$entry->id.'/reschedule'.$suffix, ['scheduled_date' => '2026-11-03', 'scope' => 'one', 'signature' => str_repeat('a', 64), 'confirmed' => true])->assertForbidden();
        }
        $this->assertSame($before, $entry->fresh()->getAttributes());
    }

    public function test_owner_checks_stale_signatures_confirmation_and_double_submission(): void
    {
        $this->activate();
        $entry = $this->entry();
        $url = '/schedule-entries/'.$entry->id.'/reschedule';
        $data = ['scheduled_date' => '2026-11-03', 'scheduled_time' => '18:00', 'scope' => 'future'];
        $preview = $this->postJson($url.'-preview', $data)->assertOk()->json('preview');
        $this->entry('2026-11-09')->update(['status' => 'completed']);
        $this->postJson($url, [...$data, 'signature' => $preview['signature'], 'confirmed' => true])->assertUnprocessable()->assertJsonValidationErrors('schedule');
        $preview = $this->postJson($url.'-preview', $data)->assertOk()->json('preview');
        $this->postJson($url, [...$data, 'signature' => $preview['signature']])->assertUnprocessable();
        $this->actingAs(User::factory()->create())->postJson($url.'-preview', $data)->assertForbidden();
        $this->actingAs($this->user)->postJson($url, [...$data, 'signature' => $preview['signature'], 'confirmed' => true])->assertOk();
        $before = ScheduleEntry::orderBy('id')->get()->map->getAttributes()->all();
        $this->postJson($url, [...$data, 'signature' => $preview['signature'], 'confirmed' => true])->assertUnprocessable();
        $this->assertSame($before, ScheduleEntry::orderBy('id')->get()->map->getAttributes()->all());
    }

    public function test_manual_session_moves_without_changing_any_recurrence_and_time_requires_future(): void
    {
        $entry = $this->user->scheduleEntries()->create(['title' => 'Extra', 'entry_type' => 'rest', 'status' => 'planned', 'scheduled_date' => '2026-11-02']);
        $this->move($entry, '2026-11-04');
        $this->assertNull($entry->fresh()->plan_occurrence_key);
        $this->assertSame([1], $this->day->fresh()->weekdays);
        $this->postJson('/schedule-entries/'.$entry->id.'/reschedule-preview', ['scheduled_date' => '2026-10-28', 'scope' => 'one'])->assertUnprocessable();
        $this->postJson('/schedule-entries/'.$entry->id.'/reschedule-preview', ['scheduled_date' => '2026-10-28', 'scheduled_time' => '19:00', 'scope' => 'one'])->assertOk();
    }

    public function test_stop_removes_only_later_eligible_sessions_and_future_stop_keeps_rolling_until_its_boundary(): void
    {
        $this->activate();
        $protected = $this->entry('2026-12-07');
        $protected->update(['status' => 'completed']);
        $before = $protected->getAttributes();
        $moved = $this->entry('2026-11-09');
        $this->move($moved, '2027-04-01');
        $this->stop('2026-11-16');
        $this->assertSame('2026-11-16', $this->plan->fresh()->end_date->toDateString());
        $this->get('/calendar');
        $this->assertSame($before, $protected->fresh()->getAttributes());
        $this->assertSame('2027-04-01', $moved->fresh()->scheduled_date->toDateString());
        $this->assertSame(0, ScheduleEntry::where('schedule_managed', true)->where('status', 'planned')->whereDate('scheduled_date', '>', '2026-11-16')->count());
        $this->stop('2027-03-15');
        $this->travelTo(now()->setDate(2027, 2, 1));
        $this->get('/calendar');
        $this->assertNotNull($this->entry('2027-03-15'));
        $this->assertSame(0, ScheduleEntry::where('schedule_managed', true)->whereDate('scheduled_date', '>', '2027-03-15')->count());
    }

    public function test_stop_stale_preview_is_rejected_and_plan_edit_requires_reactivation(): void
    {
        $this->activate();
        $url = '/training-plans/'.$this->plan->id.'/stop-schedule';
        $preview = $this->postJson($url.'-preview', ['end_date' => '2026-11-16'])->assertOk()->json('preview');
        $this->entry('2026-11-23')->update(['started_at' => now()]);
        $this->postJson($url, ['end_date' => '2026-11-16', 'confirmed' => true, 'signature' => $preview['signature']])->assertUnprocessable();
        $this->put('/training-plans/'.$this->plan->id, ['name' => $this->plan->name, 'schedule_mode' => 'ongoing', 'start_date' => '2026-11-05'])->assertSessionHasNoErrors();
        $this->assertFalse($this->plan->fresh()->schedule_enabled);
    }

    public function test_cancelled_generated_occurrence_is_not_recreated_and_copies_have_no_activation_or_exceptions(): void
    {
        $this->activate();
        $entry = $this->entry();
        $this->delete('/schedule-entries/'.$entry->id)->assertRedirect();
        $this->assertDatabaseHas('schedule_occurrence_cancellations', ['occurrence_key' => $entry->plan_occurrence_key]);
        $this->get('/calendar');
        $this->activate();
        $this->assertDatabaseMissing('schedule_entries', ['plan_occurrence_key' => $entry->plan_occurrence_key]);
        $this->post('/training-plans/'.$this->plan->id.'/duplicate', ['confirmed' => true])->assertSessionHasNoErrors();
        $copy = TrainingPlan::latest('id')->first();
        $this->assertSame('ongoing', $copy->schedule_mode);
        $this->assertFalse($copy->schedule_enabled);
        $this->assertNull($copy->schedule_actor_id);
        $this->assertSame(0, DB::table('schedule_occurrence_cancellations')->where('training_plan_id', $copy->id)->count());
        $this->get('/calendar');
        $this->assertSame(0, ScheduleEntry::whereIn('training_day_id', $copy->trainingDays->modelKeys())->count());
    }

    public function test_stopping_before_the_start_removes_the_future_schedule_without_deleting_the_plan(): void
    {
        $this->activate();
        $this->stop('2026-10-28');
        $this->assertDatabaseCount('schedule_entries', 0);
        $this->get('/calendar')->assertOk();
        $this->assertDatabaseCount('schedule_entries', 0);
        $this->assertNotNull($this->plan->fresh());
        $this->put('/training-plans/'.$this->plan->id, ['name' => 'Still editable', 'schedule_mode' => 'ongoing', 'start_date' => '2026-11-01', 'end_date' => '2026-10-28'])->assertSessionHasNoErrors();
        $this->assertSame('2026-10-28', $this->plan->fresh()->end_date->toDateString());
    }

    public function test_renaming_a_stopped_plan_preserves_stop_until_explicitly_cleared(): void
    {
        $this->activate();
        $this->stop('2026-11-16');
        $data = ['name' => 'Renamed', 'schedule_mode' => 'ongoing', 'start_date' => '2026-11-01', 'end_date' => '2026-11-16'];
        $this->put('/training-plans/'.$this->plan->id, $data)->assertSessionHasNoErrors();
        $this->assertSame('2026-11-16', $this->plan->fresh()->end_date->toDateString());
        $this->put('/training-plans/'.$this->plan->id, [...$data, 'end_date' => ''])->assertSessionHasNoErrors();
        $this->assertNull($this->plan->fresh()->end_date);
        $this->assertFalse($this->plan->fresh()->schedule_enabled);
    }

    public function test_copying_a_day_into_an_active_plan_requires_schedule_review(): void
    {
        $this->activate();
        $count = ScheduleEntry::count();
        $this->post('/training-days/'.$this->day->id.'/duplicate', ['confirmed' => true])->assertSessionHasNoErrors();
        $this->assertFalse($this->plan->fresh()->schedule_enabled);
        $this->get('/calendar')->assertOk();
        $this->assertSame($count, ScheduleEntry::count());
    }

    public function test_fixed_future_change_never_extends_the_plan_and_retains_moved_exceptions(): void
    {
        $this->plan->update(['schedule_mode' => 'fixed', 'end_date' => '2026-11-30']);
        $this->activate();
        $exception = $this->entry('2026-11-16');
        $this->move($exception, '2026-11-20');
        $before = $exception->fresh()->getAttributes();
        $this->move($this->entry(), '2026-11-03', 'future');
        $this->activate();
        $this->assertSame($before, $exception->fresh()->getAttributes());
        $this->assertSame(0, ScheduleEntry::whereDate('scheduled_date', '>', '2026-11-30')->count());
        $this->assertDatabaseMissing('schedule_entries', ['scheduled_date' => '2026-11-16']);
    }

    private function coaching(): array
    {
        $coach = User::factory()->create();
        $coach->forceFill(['account_type' => 'coach'])->save();
        $coach->coachProfile()->create(['display_name' => 'Coach']);
        $relationship = CoachTraineeRelationship::create(['coach_user_id' => $coach->id, 'trainee_user_id' => $this->user->id, 'status' => 'active', 'requested_at' => now(), 'approved_at' => now()]);
        $subscription = $relationship->subscriptions()->create(['package_name' => 'Coaching', 'starts_on' => today(), 'ends_on' => '2026-11-30', 'status' => 'active']);
        $this->plan->forceFill(['workspace_type' => 'coach_assigned'])->save();
        $assignment = $relationship->assignments()->create(['coach_subscription_id' => $subscription->id, 'assigned_training_plan_id' => $this->plan->id, 'assigned_at' => now(), 'status' => 'active']);
        $this->actingAs($coach);
        $base = '/coach/trainees/'.$this->user->id;

        return [$coach, $relationship, $subscription, $assignment, $base];
    }

    public function test_coach_ongoing_generation_keeps_owner_attribution_and_subscription_boundary_on_trainee_calendar(): void
    {
        [$coach, $relationship, $subscription, $assignment, $base] = $this->coaching();
        $this->activate($base);
        $this->move($this->entry(), '2026-11-03', 'future', $base);
        $this->actingAs($this->user)->get('/calendar')->assertOk();
        foreach (ScheduleEntry::all() as $entry) {
            $this->assertSame($this->user->id, $entry->user_id);
            $this->assertSame($coach->id, $entry->scheduled_by_user_id);
            $this->assertLessThanOrEqual('2026-11-30', $entry->scheduled_date->toDateString());
        }
        $subscription->update(['ends_on' => null]);
        $this->get('/calendar');
        $this->assertGreaterThan('2026-11-30', substr(ScheduleEntry::max('scheduled_date'), 0, 10));
        $this->assertSame(0, ScheduleEntry::where('scheduled_by_user_id', $this->user->id)->count());
    }

    public static function stoppedCoaching(): array
    {
        return [['paused'], ['ended'], ['expired'], ['disconnected'], ['ended_assignment']];
    }

    #[DataProvider('stoppedCoaching')]
    public function test_coaching_revocation_stops_generation_and_both_move_scopes(string $state): void
    {
        [$coach, $relationship, $subscription, $assignment, $base] = $this->coaching();
        $this->activate($base);
        $entry = $this->entry();
        $moves = [];
        foreach (['one', 'future'] as $scope) {
            $data = ['scheduled_date' => '2026-11-03', 'scheduled_time' => '18:00', 'scope' => $scope];
            $preview = $this->postJson($base.'/schedule-entries/'.$entry->id.'/reschedule-preview', $data)->assertOk()->json('preview');
            $moves[$scope] = $data + ['signature' => $preview['signature'], 'confirmed' => true];
        }
        $stopUrl = $base.'/training-plans/'.$this->plan->id.'/stop-schedule';
        $stop = ['end_date' => '2026-11-15'];
        $stopPreview = $this->postJson($stopUrl.'-preview', $stop)->assertOk()->json('preview');
        match ($state) {
            'paused', 'ended' => $subscription->update(['status' => $state]),
            'expired' => $subscription->update(['ends_on' => today()->subDay()]),
            'disconnected' => $relationship->update(['status' => 'ended']),
            'ended_assignment' => $assignment->update(['status' => 'ended']),
        };
        $before = ScheduleEntry::orderBy('id')->get()->map->getAttributes()->all();
        foreach (['one', 'future'] as $scope) {
            $response = $this->postJson($base.'/schedule-entries/'.$entry->id.'/reschedule-preview', ['scheduled_date' => '2026-11-03', 'scheduled_time' => '18:00', 'scope' => $scope]);
            $this->assertContains($response->status(), [403, 404]);
            $response = $this->postJson($base.'/schedule-entries/'.$entry->id.'/reschedule', $moves[$scope]);
            $this->assertContains($response->status(), [403, 404]);
        }
        $this->assertContains($this->postJson($stopUrl.'-preview', $stop)->status(), [403, 404]);
        $this->assertContains($this->postJson($stopUrl, $stop + ['signature' => $stopPreview['signature'], 'confirmed' => true])->status(), [403, 404]);
        $this->assertNull($this->plan->fresh()->end_date);
        $this->actingAs($this->user)->get('/calendar')->assertOk();
        $this->assertSame($before, ScheduleEntry::orderBy('id')->get()->map->getAttributes()->all());
    }

    public function test_other_coach_and_personal_sessions_are_visible_but_never_draggable_by_coach(): void
    {
        [$coach, $relationship, $subscription, $assignment, $base] = $this->coaching();
        $this->activate($base);
        foreach ([$this->user->id, User::factory()->create()->id] as $by) {
            $entry = $this->entry();
            $entry->update(['scheduled_by_user_id' => $by]);
            $this->get($base.'/schedule-entries/'.$entry->id)->assertOk();
            foreach (['one', 'future'] as $scope) {
                $this->postJson($base.'/schedule-entries/'.$entry->id.'/reschedule-preview', ['scheduled_date' => '2026-11-03', 'scheduled_time' => '18:00', 'scope' => $scope])->assertForbidden();
            }
            $props = $this->get($base.'/calendar')->assertOk()->viewData('page')['props'];
            $this->assertFalse(collect($props['entries'])->firstWhere('id', $entry->id)['can_move']);
        }
    }
}
