<?php

namespace Tests\Feature;

use App\Models\ScheduleEntry;
use App\Models\TrainingPlan;
use App\Models\User;
use App\Services\PlanSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlanManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private TrainingPlan $plan;

    private $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 10, 28)->setTime(12, 0));
        Storage::fake('public');
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        $this->plan = $this->user->trainingPlans()->create(['name' => 'Strength', 'description' => 'Keep me', 'start_date' => '2026-10-28', 'end_date' => '2026-11-05']);
        $this->day = $this->plan->trainingDays()->create(['title' => 'Upper body', 'type' => 'training', 'focus_area' => 'Chest', 'notes' => 'Day notes', 'icon' => 'weight', 'weekdays' => [4], 'default_time' => '18:00', 'position' => 3]);
        foreach (['thumb.jpg', 'icon.png'] as $path) {
            Storage::disk('public')->put($path, 'image-'.$path);
        }
        foreach (['reps', 'duration'] as $i => $mode) {
            $this->day->exercises()->create(['name' => 'Exercise '.$i, 'sets' => 3, 'reps' => $i ? '45 sec' : '8-12', 'tracking_mode' => $mode, 'target_weight' => $i ? null : 80.25, 'superset_group' => 'A', 'library_key' => 'builtin:Pushups', 'position' => $i + 2, 'notes' => 'Exercise notes', 'thumbnail_path' => 'thumb.jpg', 'custom_icon_path' => 'icon.png', 'icon' => 'weight']);
        }
    }

    private function preview(): array
    {
        return app(PlanSchedule::class)->preview($this->plan->fresh());
    }

    private function schedule(string $action = 'add', bool $remove = false, ?string $signature = null)
    {
        return $this->post('/training-plans/'.$this->plan->id.'/schedule', ['signature' => $signature ?? $this->preview()['signature'], 'action' => $action, 'remove_obsolete' => $remove, 'confirmed' => true]);
    }

    private function entry(array $extra = []): ScheduleEntry
    {
        return $this->user->scheduleEntries()->create(['training_day_id' => $this->day->id, 'title' => 'Future generated', 'entry_type' => 'training', 'status' => 'planned', 'scheduled_date' => '2026-11-06', 'scheduled_time' => '18:00', 'plan_occurrence_key' => 'plan:'.$this->plan->id.':'.$this->day->id.':2026-11-06', 'schedule_managed' => true, ...$extra]);
    }

    private function duplicatePlan(): TrainingPlan
    {
        $this->post('/training-plans/'.$this->plan->id.'/duplicate', ['confirmed' => true])->assertSessionHasNoErrors();

        return $this->user->trainingPlans()->latest('id')->first();
    }

    public function test_plan_copy_preserves_all_prescriptions_order_preferences_and_independent_media_without_history(): void
    {
        $rest = $this->plan->trainingDays()->create(['title' => 'Rest', 'type' => 'rest', 'position' => 7, 'weekdays' => [5, 6], 'default_time' => '08:00']);
        foreach (['planned', 'completed', 'skipped'] as $i => $status) {
            $this->entry(['plan_occurrence_key' => null, 'status' => $status, 'started_at' => $i === 0 ? now() : null, 'workout_snapshot' => ['exercises' => [['actual_weight' => 90]]]]);
        }
        $entry = ScheduleEntry::first();
        $movement = $entry->sessionExercises()->create(['source_training_exercise_id' => $this->day->exercises()->first()->id, 'source_exercise_key' => 'builtin:Pushups', 'name' => 'Actual', 'tracking_mode' => 'reps', 'planned_sets' => 1, 'position' => 0, 'status' => 'completed']);
        $movement->sets()->create(['set_number' => 1, 'actual_reps' => 20, 'actual_weight_kg' => 0, 'completed_at' => now()]);
        $before = ScheduleEntry::get()->map->getRawOriginal()->all();
        $copy = $this->duplicatePlan();
        $this->assertSame('Strength — Copy', $copy->name);
        $this->assertEquals($this->plan->only('description', 'start_date', 'end_date'), $copy->only('description', 'start_date', 'end_date'));
        $this->assertSame([3, 7], $copy->trainingDays->pluck('position')->all());
        $this->assertSame(['training', 'rest'], $copy->trainingDays->pluck('type')->all());
        $newDay = $copy->trainingDays->first();
        $this->assertSame($this->day->fresh()->only('title', 'type', 'focus_area', 'notes', 'weekdays', 'default_time', 'icon'), $newDay->only('title', 'type', 'focus_area', 'notes', 'weekdays', 'default_time', 'icon'));
        foreach ($this->day->exercises as $i => $old) {
            $new = $newDay->exercises[$i];
            $fields = ['name', 'sets', 'reps', 'target_weight', 'tracking_mode', 'superset_group', 'position', 'library_key', 'notes', 'icon'];
            $this->assertSame($old->only($fields), $new->only($fields));
            $this->assertNotSame($old->id, $new->id);
            foreach (['thumbnail_path', 'custom_icon_path'] as $field) {
                $this->assertNotSame($old->$field, $new->$field);
                $this->assertSame(Storage::disk('public')->get($old->$field), Storage::disk('public')->get($new->$field));
            }
        }
        $this->assertSame($before, ScheduleEntry::get()->map->getRawOriginal()->all());
        $this->assertDatabaseCount('session_exercises', 1);
        $this->assertDatabaseCount('session_sets', 1);
        $this->assertSame(0, ScheduleEntry::whereIn('training_day_id', $copy->trainingDays->modelKeys())->count());
        $this->delete('/training-plans/'.$this->plan->id)->assertRedirect();
        Storage::disk('public')->assertExists($newDay->exercises->first()->thumbnail_path);
    }

    public function test_copy_names_increment_and_remain_within_the_unicode_name_limit(): void
    {
        $this->plan->update(['name' => str_repeat('界', 150)]);
        $names = [];
        for ($i = 1; $i <= 3; $i++) {
            $names[] = $this->duplicatePlan()->name;
        }
        $this->assertCount(3, array_unique($names));
        $this->assertTrue(str_ends_with($names[0], ' — Copy'));
        $this->assertTrue(str_ends_with($names[2], ' — Copy 3'));
        foreach ($names as $name) {
            $this->assertSame(150, Str::length($name));
        }
    }

    public function test_duplicate_day_appends_to_same_plan_and_repeated_names_increment(): void
    {
        $before = $this->day->fresh()->getRawOriginal();
        $this->entry();
        for ($i = 1; $i <= 2; $i++) {
            $this->post('/training-days/'.$this->day->id.'/duplicate', ['confirmed' => true])->assertSessionHasNoErrors();
            $copy = $this->plan->trainingDays()->reorder()->latest('id')->first();
            $this->assertSame('Upper body — Copy'.($i > 1 ? ' 2' : ''), $copy->title);
            $this->assertSame(3 + $i, $copy->position);
            $this->assertSame(['A', 'A'], $copy->exercises->pluck('superset_group')->all());
            $this->assertSame([2, 3], $copy->exercises->pluck('position')->all());
        }
        $this->assertSame($before, $this->day->fresh()->getRawOriginal());
        $this->assertDatabaseCount('schedule_entries', 1);
    }

    public function test_copy_to_other_owned_plan_is_independent_and_keeps_original_identity(): void
    {
        $other = $this->user->trainingPlans()->create(['name' => 'Other']);
        $this->post('/training-days/'.$this->day->id.'/duplicate', ['confirmed' => true, 'training_plan_id' => $other->id])->assertSessionHasNoErrors();
        $copy = $other->trainingDays()->sole();
        $copy->exercises()->first()->update(['target_weight' => 100]);
        $copy->update(['title' => 'Edited copy']);
        $this->assertSame('80.25', $this->day->exercises()->first()->target_weight);
        $this->assertSame('Upper body', $this->day->fresh()->title);
        $this->assertSame('builtin:Pushups', $copy->exercises()->first()->library_key);
        $this->assertSame(0, ScheduleEntry::count());
    }

    public function test_copy_rejects_unowned_source_and_destination_and_requires_confirmation(): void
    {
        $other = User::factory()->create();
        $plan = $other->trainingPlans()->create(['name' => 'Private']);
        $day = $plan->trainingDays()->create(['title' => 'Private', 'type' => 'rest']);
        $this->post('/training-plans/'.$plan->id.'/duplicate', ['confirmed' => true])->assertForbidden();
        $this->post('/training-days/'.$day->id.'/duplicate', ['confirmed' => true])->assertForbidden();
        $this->post('/training-days/'.$this->day->id.'/duplicate', ['confirmed' => true, 'training_plan_id' => $plan->id])->assertForbidden();
        $this->post('/training-plans/'.$this->plan->id.'/duplicate')->assertSessionHasErrors('confirmed');
        $this->post('/training-days/'.$this->day->id.'/duplicate')->assertSessionHasErrors('confirmed');
        $this->assertDatabaseCount('training_plans', 2);
        $this->assertDatabaseCount('training_days', 2);
    }

    public function test_failed_media_copy_rolls_back_database_and_new_files(): void
    {
        $this->day->exercises()->orderByDesc('id')->first()->update(['custom_icon_path' => 'missing.png']);
        $before = Storage::disk('public')->allFiles();
        $this->post('/training-plans/'.$this->plan->id.'/duplicate', ['confirmed' => true])->assertStatus(500);
        $this->assertDatabaseCount('training_plans', 1);
        $this->assertDatabaseCount('training_days', 1);
        $this->assertDatabaseCount('training_exercises', 2);
        $this->assertEqualsCanonicalizing($before, Storage::disk('public')->allFiles());
    }

    public function test_diff_add_keep_remove_and_protected_are_distinct_and_preview_has_no_side_effects(): void
    {
        $keep = $this->entry(['scheduled_date' => '2026-10-29', 'plan_occurrence_key' => 'plan:'.$this->plan->id.':'.$this->day->id.':2026-10-29']);
        $remove = $this->entry();
        $manual = $this->entry(['plan_occurrence_key' => null]);
        $before = ScheduleEntry::get()->map->getRawOriginal()->all();
        $p = $this->preview();
        $this->assertSame(['2026-11-05'], array_column($p['add'], 'scheduled_date'));
        $this->assertSame([$keep->id], array_column($p['keep'], 'id'));
        $this->assertSame([$remove->id], array_column($p['remove'], 'id'));
        $this->assertSame([$manual->id], array_column($p['protected'], 'id'));
        $this->get('/training-plans/'.$this->plan->id.'/schedule-preview')->assertInertia(fn (Assert $p) => $p->component('TrainingPlans/Schedule')->has('preview.add', 1)->has('preview.protected', 1));
        $this->assertSame($before, ScheduleEntry::get()->map->getRawOriginal()->all());
    }

    public static function protectedRows(): array
    {
        return [
            'completed' => [['status' => 'completed']], 'skipped' => [['status' => 'skipped']],
            'active' => [['started_at' => '2026-10-28 11:00:00']], 'saved snapshot' => [['workout_snapshot' => ['exercises' => []]]],
            'manual' => [['plan_occurrence_key' => null]], 'legacy or edited' => [['schedule_managed' => false]],
            'past' => [['scheduled_date' => '2026-10-27']], 'today untimed' => [['scheduled_date' => '2026-10-28', 'scheduled_time' => null]],
            'today elapsed' => [['scheduled_date' => '2026-10-28', 'scheduled_time' => '11:59']], 'today current minute' => [['scheduled_date' => '2026-10-28', 'scheduled_time' => '12:00']],
            'moved future' => [['scheduled_date' => '2026-11-07']],
        ];
    }

    #[DataProvider('protectedRows')]
    public function test_protected_sessions_are_never_removed_or_rewritten(array $attributes): void
    {
        $entry = $this->entry($attributes);
        $before = $entry->fresh()->getRawOriginal();
        $this->assertSame([$entry->id], array_column($this->preview()['protected'], 'id'));
        $this->schedule('remove')->assertSessionHasNoErrors();
        $this->schedule('regenerate', true)->assertSessionHasNoErrors();
        $this->assertSame($before, $entry->fresh()->getRawOriginal());
    }

    public function test_structured_records_protect_even_unstarted_planned_sessions_with_empty_sets(): void
    {
        $entry = $this->entry();
        $movement = $entry->sessionExercises()->create(['name' => 'Saved', 'tracking_mode' => 'reps', 'position' => 0, 'planned_sets' => 1]);
        $this->assertCount(1, $this->preview()['protected']);
        $this->schedule('remove')->assertSessionHasNoErrors();
        $movement->sets()->create(['set_number' => 1, 'actual_reps' => 12, 'completed_at' => now()]);
        $this->schedule('regenerate', true)->assertSessionHasNoErrors();
        $this->assertNotNull($entry->fresh());
        $this->assertDatabaseCount('session_sets', 1);
    }

    public function test_add_missing_is_idempotent_and_stale_replays_never_add_unpreviewed_dates(): void
    {
        $signature = $this->preview()['signature'];
        $this->schedule('add', false, $signature)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('schedule_entries', 2);
        $this->schedule('add', false, $signature)->assertSessionHasErrors('schedule');
        $this->schedule()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('schedule_entries', 2);
        $this->assertSame(2, ScheduleEntry::where('schedule_managed', true)->count());
        $this->assertSame(2, ScheduleEntry::distinct()->count('plan_occurrence_key'));
    }

    public function test_matching_manual_or_completed_occurrence_prevents_duplicate_generation(): void
    {
        $entry = $this->entry(['plan_occurrence_key' => null, 'scheduled_date' => '2026-10-29', 'status' => 'completed']);
        $before = $entry->fresh()->getRawOriginal();
        $this->assertSame(1, $this->preview()['new_count']);
        $this->schedule()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('schedule_entries', 2);
        $this->assertSame($before, $entry->fresh()->getRawOriginal());
    }

    public function test_regeneration_can_add_only_or_remove_obsolete_and_preserves_matching_sessions(): void
    {
        $this->schedule()->assertSessionHasNoErrors();
        $obsolete = $this->entry();
        $kept = ScheduleEntry::where('id', '!=', $obsolete->id)->get()->map->getRawOriginal()->all();
        $this->day->update(['weekdays' => [4, 5]]);
        $this->schedule('regenerate')->assertSessionHasNoErrors();
        $this->assertNotNull($obsolete->fresh());
        $this->assertDatabaseCount('schedule_entries', 4);
        $this->schedule('regenerate', true)->assertSessionHasNoErrors();
        $this->assertNull($obsolete->fresh());
        $this->assertDatabaseCount('schedule_entries', 3);
        $this->schedule('regenerate', true)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('schedule_entries', 3);
        foreach ($kept as $row) {
            $this->assertSame($row, ScheduleEntry::find($row['id'])->getRawOriginal());
        }
    }

    public function test_time_changes_require_explicit_replacement_and_reuse_the_existing_occurrence_key(): void
    {
        $this->schedule()->assertSessionHasNoErrors();
        $keys = ScheduleEntry::pluck('plan_occurrence_key')->all();
        $this->day->update(['default_time' => '19:30']);
        $p = $this->preview();
        $this->assertCount(2, $p['remove']);
        $this->assertSame(2, $p['replacement_count']);
        $this->assertSame(0, $p['new_count']);
        $this->schedule('add')->assertSessionHasNoErrors();
        $this->schedule('regenerate')->assertSessionHasNoErrors();
        $this->assertSame(2, ScheduleEntry::get()->filter(fn ($row) => substr($row->scheduled_time, 0, 5) === '18:00')->count());
        $this->schedule('regenerate', true)->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing($keys, ScheduleEntry::pluck('plan_occurrence_key')->all());
        $this->assertSame(2, ScheduleEntry::get()->filter(fn ($row) => substr($row->scheduled_time, 0, 5) === '19:30')->count());
        $this->assertSame('80.25', $this->day->exercises()->first()->target_weight);
    }

    public function test_manual_time_edit_opts_out_without_losing_original_duplicate_prevention(): void
    {
        $this->schedule()->assertSessionHasNoErrors();
        $entry = ScheduleEntry::first();
        $key = $entry->plan_occurrence_key;
        $this->put('/schedule-entries/'.$entry->id, ['entry_type' => 'training', 'training_day_id' => $this->day->id, 'title' => $entry->title, 'scheduled_date' => '2026-12-20', 'scheduled_time' => '09:00'])->assertSessionHasNoErrors();
        $this->assertFalse($entry->fresh()->schedule_managed);
        $this->assertSame($key, $entry->fresh()->plan_occurrence_key);
        $before = $entry->fresh()->getRawOriginal();
        $this->schedule()->assertSessionHasNoErrors();
        $this->schedule('remove')->assertSessionHasNoErrors();
        $this->assertSame($before, $entry->fresh()->getRawOriginal());
        $this->assertSame('18:00', substr($this->day->fresh()->default_time, 0, 5));
    }

    public function test_stale_preview_after_status_rule_time_or_manual_booking_change_cannot_delete_anything(): void
    {
        $entry = $this->entry();
        $signature = $this->preview()['signature'];
        $entry->update(['started_at' => now()]);
        $this->schedule('remove', false, $signature)->assertSessionHasErrors('schedule');
        $this->assertNotNull($entry->fresh());
        $signature = $this->preview()['signature'];
        $this->day->update(['weekdays' => [5]]);
        $this->schedule('regenerate', true, $signature)->assertSessionHasErrors('schedule');
        $signature = $this->preview()['signature'];
        $this->entry(['plan_occurrence_key' => null]);
        $this->schedule('regenerate', true, $signature)->assertSessionHasErrors('schedule');
        $this->assertDatabaseCount('schedule_entries', 2);
    }

    public function test_plan_and_day_changes_only_prompt_for_review_and_do_not_touch_calendar(): void
    {
        $entry = $this->entry();
        $before = $entry->fresh()->getRawOriginal();
        $this->put('/training-plans/'.$this->plan->id, ['name' => 'Changed', 'start_date' => '2026-11-01', 'end_date' => '2026-11-04'])->assertSessionHas('schedule_review.id', $this->plan->id);
        $this->put('/training-days/'.$this->day->id, ['title' => 'New day', 'type' => 'training', 'weekdays' => [0, 2], 'default_time' => '09:00', 'exercises' => []])->assertSessionHas('schedule_review.id', $this->plan->id);
        $this->assertSame($before, $entry->fresh()->getRawOriginal());
    }

    public function test_future_boundary_and_deleted_day_occurrences_are_handled_safely(): void
    {
        $this->day->update(['weekdays' => [3], 'default_time' => '12:01']);
        $this->assertSame(['2026-10-28', '2026-11-04'], array_column($this->preview()['sessions'], 'scheduled_date'));
        $this->day->update(['default_time' => null]);
        $this->assertSame(['2026-11-04'], array_column($this->preview()['sessions'], 'scheduled_date'));
        $entry = $this->entry();
        $this->delete('/training-days/'.$this->day->id)->assertRedirect();
        $this->assertSame([$entry->id], array_column($this->preview()['remove'], 'id'));
        $this->schedule('remove')->assertSessionHasNoErrors();
        $this->assertNull($entry->fresh());
    }

    public function test_management_authorization_confirmation_and_forged_previews(): void
    {
        $this->entry();
        $this->post('/training-plans/'.$this->plan->id.'/schedule', ['signature' => $this->preview()['signature'], 'action' => 'remove'])->assertSessionHasErrors('confirmed');
        $this->schedule('remove', false, str_repeat('0', 64))->assertSessionHasErrors('schedule');
        $this->actingAs(User::factory()->create());
        $this->get('/training-plans/'.$this->plan->id.'/schedule-preview')->assertForbidden();
        $this->schedule('remove')->assertForbidden();
        $this->assertDatabaseCount('schedule_entries', 1);
    }

    public function test_stale_preview_crossing_the_session_time_cannot_remove_it(): void
    {
        $entry = $this->entry(['scheduled_date' => '2026-10-28', 'scheduled_time' => '12:01', 'plan_occurrence_key' => 'plan:'.$this->plan->id.':'.$this->day->id.':2026-10-28']);
        $signature = $this->preview()['signature'];
        $this->travel(2)->minutes();
        $this->schedule('remove', false, $signature)->assertSessionHasErrors('schedule');
        $this->assertNotNull($entry->fresh());
    }

    public function test_time_only_manual_edit_is_protected_even_after_later_plan_time_changes(): void
    {
        $this->schedule()->assertSessionHasNoErrors();
        $entry = ScheduleEntry::first();
        $this->put('/schedule-entries/'.$entry->id, ['entry_type' => 'training', 'training_day_id' => $this->day->id, 'title' => $entry->title, 'scheduled_date' => $entry->scheduled_date->toDateString(), 'scheduled_time' => '20:00', 'schedule_managed' => true])->assertSessionHasNoErrors();
        $this->assertFalse($entry->fresh()->schedule_managed);
        $before = $entry->fresh()->getRawOriginal();
        $this->day->update(['default_time' => '21:00']);
        $this->schedule('regenerate', true)->assertSessionHasNoErrors();
        $this->assertSame($before, $entry->fresh()->getRawOriginal());
        $this->assertDatabaseCount('schedule_entries', 2);
    }

    public function test_legacy_generated_sessions_remain_independent_and_still_reserve_their_occurrence(): void
    {
        $entry = $this->user->scheduleEntries()->create(['training_day_id' => $this->day->id, 'title' => 'Older occurrence', 'entry_type' => 'training', 'status' => 'planned', 'scheduled_date' => '2026-10-29', 'scheduled_time' => '17:00', 'plan_occurrence_key' => 'plan:'.$this->plan->id.':'.$this->day->id.':2026-10-29']);
        $this->assertFalse($entry->fresh()->schedule_managed);
        $before = $entry->fresh()->getRawOriginal();
        $this->assertSame(1, $this->preview()['new_count']);
        $this->schedule('regenerate', true)->assertSessionHasNoErrors();
        $this->schedule('remove')->assertSessionHasNoErrors();
        $this->assertSame($before, $entry->fresh()->getRawOriginal());
        $this->assertDatabaseCount('schedule_entries', 1);
    }

    public function test_manually_created_session_cannot_claim_generated_origin_or_bulk_management(): void
    {
        $this->post('/schedule-entries', ['entry_type' => 'training', 'training_day_id' => $this->day->id, 'title' => 'Manual', 'scheduled_date' => '2026-11-06', 'schedule_managed' => true, 'plan_occurrence_key' => 'plan:'.$this->plan->id.':'.$this->day->id.':2026-11-06'])->assertSessionHasNoErrors();
        $entry = ScheduleEntry::sole();
        $this->assertNull($entry->plan_occurrence_key);
        $this->assertFalse($entry->schedule_managed);
        $this->schedule('remove')->assertSessionHasNoErrors();
        $this->assertNotNull($entry->fresh());
    }

    public function test_schedule_management_does_not_change_any_exercise_prescriptions(): void
    {
        $before = $this->day->exercises()->get()->map->getRawOriginal()->all();
        $this->schedule()->assertSessionHasNoErrors();
        $this->day->update(['weekdays' => [5], 'default_time' => '09:30']);
        $this->schedule('regenerate', true)->assertSessionHasNoErrors();
        $this->schedule('remove')->assertSessionHasNoErrors();
        $this->assertSame($before, $this->day->exercises()->get()->map->getRawOriginal()->all());
    }
}
