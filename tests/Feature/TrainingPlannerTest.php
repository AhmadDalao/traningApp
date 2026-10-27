<?php

namespace Tests\Feature;

use App\Models\ScheduleEntry;
use App\Models\TrainingDay;
use App\Models\TrainingExercise;
use App\Models\TrainingPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TrainingPlannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 10, 21)->setTime(12, 0));
    }

    private function plan(?User $user = null): TrainingPlan
    {
        return ($user ?? User::factory()->create())->trainingPlans()->create(['name' => 'Weekly Plan']);
    }

    private function day(?TrainingPlan $plan = null, array $attributes = []): TrainingDay
    {
        return ($plan ?? $this->plan())->trainingDays()->create([...['title' => 'Leg Day', 'type' => 'training', 'focus_area' => 'Legs', 'position' => 0], ...$attributes]);
    }

    private function exercise(TrainingDay $day, array $attributes = []): TrainingExercise
    {
        return $day->exercises()->create([...['name' => 'Squat', 'sets' => 4, 'reps' => '8-10', 'position' => 0], ...$attributes]);
    }

    private function entry(?TrainingDay $day = null, array $attributes = []): ScheduleEntry
    {
        $day ??= $this->day();

        return $day->trainingPlan->user->scheduleEntries()->create([...['training_day_id' => $day->id, 'title' => $day->title, 'entry_type' => $day->type, 'focus_area' => $day->focus_area, 'scheduled_date' => today(), 'status' => 'planned'], ...$attributes]);
    }

    private function dayPayload(array $exercises = []): array
    {
        return ['title' => 'Leg Day', 'type' => 'training', 'focus_area' => 'Legs', 'notes' => 'Controlled movement', 'exercises' => $exercises];
    }

    private function exercisePayload(array $attributes = []): array
    {
        return [...['name' => 'Leg Extension', 'sets' => 3, 'reps' => '12', 'superset_group' => 'A', 'notes' => 'Slow tempo'], ...$attributes];
    }

    public function test_guests_are_redirected_to_login(): void
    {
        foreach (['/dashboard', '/training-plans', '/calendar', '/schedule-entries/create'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    public function test_registration_logs_in_without_email_verification(): void
    {
        $this->post('/register', ['name' => 'Ahmad', 'email' => 'ahmad@example.com', 'password' => 'StrongPassword1!', 'password_confirmation' => 'StrongPassword1!'])->assertRedirect('/dashboard');
        $this->assertAuthenticated();
        $this->assertNull(User::first()->email_verified_at);
        $this->get('/dashboard')->assertOk();
    }

    public function test_registration_rejects_invalid_confirmation_and_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $this->post('/register', ['name' => 'Test', 'email' => 'taken@example.com', 'password' => 'password123', 'password_confirmation' => 'wrong'])->assertSessionHasErrors(['email', 'password']);
        $this->assertGuest();
    }

    public function test_user_can_login_and_logout(): void
    {
        $user = User::factory()->create(['password' => 'password123']);
        $this->post('/login', ['email' => $user->email, 'password' => 'password123', 'remember' => true])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_incorrect_login_is_rejected_and_rate_limited(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many attempts', session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_user_can_create_update_and_list_own_plan(): void
    {
        $user = User::factory()->create();
        $other = $this->plan();
        $this->actingAs($user)->post('/training-plans', ['name' => 'My plan', 'description' => 'My routine'])->assertRedirect();
        $plan = $user->trainingPlans()->firstOrFail();
        $this->put("/training-plans/{$plan->id}", ['name' => 'Updated', 'description' => 'New routine'])->assertRedirect("/training-plans/{$plan->id}");
        $this->get('/training-plans')->assertInertia(fn (Assert $page) => $page->has('plans.data', 1)->where('plans.data.0.name', 'Updated'));
        $this->get("/training-plans/{$plan->id}")->assertOk();
    }

    public function test_plan_validation_is_enforced(): void
    {
        $this->actingAs(User::factory()->create())->post('/training-plans', ['name' => str_repeat('x', 151)])->assertSessionHasErrors('name');
        $this->assertDatabaseCount('training_plans', 0);
    }

    public function test_training_day_exercises_sets_reps_supersets_and_order_save_together(): void
    {
        $plan = $this->plan();
        $this->actingAs($plan->user)->post("/training-plans/{$plan->id}/days", $this->dayPayload([$this->exercisePayload(['id' => null, 'reps' => '30 seconds']), $this->exercisePayload(['id' => null, 'name' => 'Leg Curl'])]))->assertRedirect();
        $day = $plan->trainingDays()->firstOrFail();
        $rows = $day->exercises;
        $this->assertCount(2, $rows);
        $this->assertEquals(3, $rows[0]->sets);
        $this->assertEquals('30 seconds', $rows[0]->reps);
        $this->assertEquals('A', $rows[0]->superset_group);
        $this->assertEquals(1, $rows[1]->position);
        $this->get("/training-days/{$day->id}")->assertInertia(fn (Assert $page) => $page->has('day.exercises', 2));
    }

    public function test_invalid_exercise_does_not_partially_create_day(): void
    {
        $plan = $this->plan();
        $this->actingAs($plan->user)->post("/training-plans/{$plan->id}/days", $this->dayPayload([$this->exercisePayload(['sets' => 0])]))->assertSessionHasErrors('exercises.0.sets');
        $this->assertDatabaseCount('training_days', 0);
        $this->assertDatabaseCount('training_exercises', 0);
    }

    public function test_rest_day_needs_no_exercises(): void
    {
        $plan = $this->plan();
        $this->actingAs($plan->user)->post("/training-plans/{$plan->id}/days", ['title' => 'Rest Day', 'type' => 'rest'])->assertRedirect();
        $this->assertDatabaseHas('training_days', ['type' => 'rest', 'focus_area' => null]);
        $this->assertDatabaseCount('training_exercises', 0);
    }

    public function test_thumbnail_upload_replace_and_remove_delete_old_files(): void
    {
        Storage::fake('public');
        $plan = $this->plan();
        $this->actingAs($plan->user)->post("/training-plans/{$plan->id}/days", $this->dayPayload([$this->exercisePayload(['thumbnail' => UploadedFile::fake()->image('first.jpg')])]))->assertSessionHasNoErrors();
        $day = $plan->trainingDays()->firstOrFail();
        $exercise = $day->exercises()->firstOrFail();
        $old = $exercise->thumbnail_path;
        Storage::disk('public')->assertExists($old);
        $this->post("/training-days/{$day->id}", ['_method' => 'put', ...$this->dayPayload([$this->exercisePayload(['id' => $exercise->id, 'thumbnail' => UploadedFile::fake()->image('new.png')])])])->assertSessionHasNoErrors();
        $new = $exercise->fresh()->thumbnail_path;
        $this->assertNotEquals($old, $new);
        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($new);
        $this->put("/training-days/{$day->id}", $this->dayPayload([$this->exercisePayload(['id' => $exercise->id, 'remove_thumbnail' => true])]))->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing($new);
        $this->assertNull($exercise->fresh()->thumbnail_path);
    }

    public function test_invalid_or_oversized_thumbnail_is_rejected(): void
    {
        Storage::fake('public');
        $plan = $this->plan();
        $this->actingAs($plan->user);
        foreach ([UploadedFile::fake()->create('bad.svg', 10, 'image/svg+xml'), UploadedFile::fake()->image('big.jpg')->size(2049)] as $file) {
            $this->post("/training-plans/{$plan->id}/days", $this->dayPayload([$this->exercisePayload(['thumbnail' => $file])]))->assertSessionHasErrors('exercises.0.thumbnail');
        }
        $this->assertDatabaseCount('training_days', 0);
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    public function test_removing_exercises_and_converting_to_rest_cleans_thumbnails(): void
    {
        Storage::fake('public');
        $day = $this->day();
        Storage::disk('public')->put('exercise-thumbnails/a.jpg', 'demo');
        $exercise = $this->exercise($day, ['thumbnail_path' => 'exercise-thumbnails/a.jpg']);
        $this->actingAs($day->trainingPlan->user)->put("/training-days/{$day->id}", $this->dayPayload([]))->assertRedirect();
        Storage::disk('public')->assertMissing('exercise-thumbnails/a.jpg');
        $this->assertDatabaseMissing('training_exercises', ['id' => $exercise->id]);
        $this->exercise($day);
        $this->put("/training-days/{$day->id}", ['title' => 'Rest', 'type' => 'rest', 'focus_area' => 'Legs'])->assertRedirect();
        $this->assertDatabaseCount('training_exercises', 0);
        $this->assertNull($day->fresh()->focus_area);
    }

    public function test_user_can_schedule_training_and_multiple_entries_on_same_date(): void
    {
        $day = $this->day();
        $this->actingAs($day->trainingPlan->user);
        for ($i = 0; $i < 2; $i++) {
            $this->post('/schedule-entries', ['training_day_id' => $day->id, 'entry_type' => 'training', 'scheduled_date' => '2026-10-21'])->assertRedirect();
        }
        $this->assertDatabaseCount('schedule_entries', 2);
        $this->assertDatabaseHas('schedule_entries', ['title' => 'Leg Day', 'focus_area' => 'Legs', 'entry_type' => 'training', 'status' => 'planned']);
    }

    public function test_direct_and_template_rest_days_are_supported(): void
    {
        $day = $this->day(null, ['title' => 'Recovery', 'type' => 'rest', 'focus_area' => null]);
        $this->actingAs($day->trainingPlan->user)->post('/schedule-entries', ['entry_type' => 'rest', 'scheduled_date' => '2026-10-21'])->assertRedirect();
        $this->post('/schedule-entries', ['entry_type' => 'rest', 'training_day_id' => $day->id, 'scheduled_date' => '2026-10-21'])->assertRedirect();
        $this->assertDatabaseHas('schedule_entries', ['title' => 'Rest Day', 'training_day_id' => null, 'entry_type' => 'rest']);
        $this->assertDatabaseHas('schedule_entries', ['title' => 'Recovery', 'training_day_id' => $day->id, 'entry_type' => 'rest']);
    }

    public function test_training_session_requires_owned_template_and_valid_date(): void
    {
        $this->actingAs(User::factory()->create())->post('/schedule-entries', ['entry_type' => 'training', 'scheduled_date' => 'invalid'])->assertSessionHasErrors(['training_day_id', 'scheduled_date']);
        $this->assertDatabaseCount('schedule_entries', 0);
    }

    public function test_all_status_transitions_work_and_keep_session_details_open(): void
    {
        $entry = $this->entry();
        $this->actingAs($entry->user);
        foreach (['completed', 'planned', 'skipped', 'completed', 'skipped', 'planned'] as $status) {
            $this->from("/schedule-entries/{$entry->id}")->patch("/schedule-entries/{$entry->id}/status", ['status' => $status])->assertRedirect("/schedule-entries/{$entry->id}");
            $this->assertEquals($status, $entry->fresh()->status);
        }
    }

    public function test_invalid_status_is_rejected(): void
    {
        $entry = $this->entry();
        $this->actingAs($entry->user)->patch("/schedule-entries/{$entry->id}/status", ['status' => 'finished'])->assertSessionHasErrors('status');
        $this->assertEquals('planned', $entry->fresh()->status);
    }

    public function test_dashboard_counts_update_and_exclude_rest_skipped_and_other_weeks(): void
    {
        $day = $this->day();
        $entry = $this->entry($day);
        $this->entry($day, ['status' => 'completed']);
        $this->entry($day, ['status' => 'skipped']);
        $this->entry($day, ['entry_type' => 'rest', 'status' => 'completed']);
        $this->entry($day, ['entry_type' => 'rest', 'status' => 'planned']);
        $this->entry($day, ['status' => 'completed', 'scheduled_date' => today()->subWeek()]);
        $this->entry(null, ['status' => 'completed']);
        $this->actingAs($entry->user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('stats.completed', 1)->where('stats.planned', 1)->where('stats.today', 5));
        $this->from('/dashboard')->patch("/schedule-entries/{$entry->id}/status", ['status' => 'completed'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('stats.completed', 2)->where('stats.planned', 0));
    }

    public function test_calendar_receives_completed_status_and_filters_to_user_and_period(): void
    {
        $entry = $this->entry();
        $this->entry(null);
        $this->entry($entry->trainingDay, ['scheduled_date' => '2027-05-12']);
        $this->actingAs($entry->user)->patch("/schedule-entries/{$entry->id}/status", ['status' => 'completed']);
        $this->get('/calendar?date=2026-10-21')->assertInertia(fn (Assert $page) => $page->component('Calendar/Index')->has('entries', 1)->where('entries.0.status', 'completed'));
        $this->get('/calendar?date=2027-05-12&view=dayGridWeek')->assertInertia(fn (Assert $page) => $page->has('entries', 1)->where('calendarView', 'dayGridWeek'));
    }

    public function test_session_can_be_edited_without_resetting_status_then_deleted(): void
    {
        $entry = $this->entry(null, ['status' => 'completed']);
        $this->actingAs($entry->user)->put("/schedule-entries/{$entry->id}", ['training_day_id' => $entry->training_day_id, 'entry_type' => 'training', 'title' => 'Morning legs', 'scheduled_date' => '2026-10-22', 'notes' => 'Updated'])->assertRedirect("/schedule-entries/{$entry->id}");
        $this->assertDatabaseHas('schedule_entries', ['id' => $entry->id, 'title' => 'Morning legs', 'status' => 'completed', 'scheduled_date' => '2026-10-22']);
        $this->delete("/schedule-entries/{$entry->id}")->assertRedirect('/calendar');
        $this->assertDatabaseMissing('schedule_entries', ['id' => $entry->id]);
    }

    public function test_deleting_plan_preserves_calendar_history_and_removes_all_thumbnails(): void
    {
        Storage::fake('public');
        $day = $this->day();
        $entry = $this->entry($day);
        Storage::disk('public')->put('exercise-thumbnails/a.jpg', 'demo');
        $this->exercise($day, ['thumbnail_path' => 'exercise-thumbnails/a.jpg']);
        $this->actingAs($entry->user)->delete("/training-plans/{$day->training_plan_id}")->assertRedirect('/training-plans');
        $this->assertDatabaseCount('training_days', 0);
        $this->assertDatabaseCount('training_exercises', 0);
        Storage::disk('public')->assertMissing('exercise-thumbnails/a.jpg');
        $this->assertNull($entry->fresh()->training_day_id);
        $this->assertEquals('Leg Day', $entry->fresh()->title);
        $this->get("/schedule-entries/{$entry->id}")->assertOk();
        $this->put("/schedule-entries/{$entry->id}", ['entry_type' => 'training', 'title' => 'Historical session', 'scheduled_date' => '2026-10-21'])->assertSessionHasNoErrors();
    }

    public function test_deleting_day_preserves_session_snapshot(): void
    {
        $day = $this->day();
        $entry = $this->entry($day);
        $this->actingAs($entry->user)->put("/training-days/{$day->id}", ['title' => 'New template', 'type' => 'training', 'focus_area' => 'Arms'])->assertRedirect();
        $this->assertEquals('Leg Day', $entry->fresh()->title);
        $this->assertEquals('Legs', $entry->fresh()->focus_area);
        $this->delete("/training-days/{$day->id}")->assertRedirect();
        $this->assertNull($entry->fresh()->training_day_id);
    }

    public function test_days_and_exercises_can_be_reordered_only_as_complete_owned_lists(): void
    {
        $day = $this->day();
        $second = $this->day($day->trainingPlan, ['position' => 1]);
        $a = $this->exercise($day);
        $b = $this->exercise($day, ['position' => 1]);
        $this->actingAs($day->trainingPlan->user)->patch("/training-plans/{$day->training_plan_id}/days/reorder", ['ids' => [$second->id, $day->id]])->assertSessionHasNoErrors();
        $this->assertEquals(0, $second->fresh()->position);
        $this->patch("/training-days/{$day->id}/exercises/reorder", ['ids' => [$b->id, $a->id]])->assertSessionHasNoErrors();
        $this->assertEquals(0, $b->fresh()->position);
        $this->patch("/training-days/{$day->id}/exercises/reorder", ['ids' => [$b->id]])->assertSessionHasErrors('ids');
        $this->patch("/training-plans/{$day->training_plan_id}/days/reorder", ['ids' => [$day->id, $day->id]])->assertSessionHasErrors('ids.0');
    }

    public function test_another_users_plan_cannot_be_read_changed_deleted_or_given_days(): void
    {
        $plan = $this->plan();
        $this->actingAs(User::factory()->create());
        foreach (["/training-plans/{$plan->id}", "/training-plans/{$plan->id}/edit", "/training-plans/{$plan->id}/days/create"] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->put("/training-plans/{$plan->id}", ['name' => 'Hacked'])->assertForbidden();
        $this->delete("/training-plans/{$plan->id}")->assertForbidden();
        $this->post("/training-plans/{$plan->id}/days", $this->dayPayload())->assertForbidden();
        $this->patch("/training-plans/{$plan->id}/days/reorder", ['ids' => []])->assertForbidden();
    }

    public function test_another_users_day_cannot_be_read_changed_deleted_or_reordered(): void
    {
        $day = $this->day();
        $this->actingAs(User::factory()->create());
        $this->get("/training-days/{$day->id}")->assertForbidden();
        $this->get("/training-days/{$day->id}/edit")->assertForbidden();
        $this->put("/training-days/{$day->id}", $this->dayPayload())->assertForbidden();
        $this->delete("/training-days/{$day->id}")->assertForbidden();
        $this->patch("/training-days/{$day->id}/exercises/reorder", ['ids' => []])->assertForbidden();
    }

    public function test_another_users_session_cannot_be_read_edited_deleted_or_status_changed(): void
    {
        $entry = $this->entry();
        $this->actingAs(User::factory()->create());
        $this->get("/schedule-entries/{$entry->id}")->assertForbidden();
        $this->get("/schedule-entries/{$entry->id}/edit")->assertForbidden();
        $this->put("/schedule-entries/{$entry->id}", ['entry_type' => 'rest', 'scheduled_date' => '2026-10-21'])->assertForbidden();
        $this->delete("/schedule-entries/{$entry->id}")->assertForbidden();
        foreach (['completed', 'skipped', 'planned'] as $status) {
            $this->patch("/schedule-entries/{$entry->id}/status", ['status' => $status])->assertForbidden();
        }
        $this->assertEquals('planned', $entry->fresh()->status);
    }

    public function test_foreign_training_day_id_cannot_be_injected_into_create_or_edit_session(): void
    {
        $entry = $this->entry();
        $foreign = $this->day();
        $payload = ['entry_type' => 'training', 'training_day_id' => $foreign->id, 'scheduled_date' => '2026-10-21'];
        $this->actingAs($entry->user)->post('/schedule-entries', $payload)->assertSessionHasErrors('training_day_id');
        $this->put("/schedule-entries/{$entry->id}", $payload)->assertSessionHasErrors('training_day_id');
        $this->assertEquals($entry->training_day_id, $entry->fresh()->training_day_id);
    }

    public function test_exercises_cannot_be_injected_from_another_day_or_user(): void
    {
        $day = $this->day();
        $foreign = $this->exercise($this->day());
        $sameUser = $this->exercise($this->day($day->trainingPlan));
        $this->actingAs($day->trainingPlan->user);
        foreach ([$foreign, $sameUser] as $exercise) {
            $this->put("/training-days/{$day->id}", $this->dayPayload([$this->exercisePayload(['id' => $exercise->id])]))->assertSessionHasErrors('exercises.0.id');
        }
        $this->post("/training-plans/{$day->training_plan_id}/days", $this->dayPayload([$this->exercisePayload(['id' => $foreign->id])]))->assertSessionHasErrors('exercises.0.id');
        $this->assertEquals('Squat', $foreign->fresh()->name);
    }

    public function test_all_form_pages_render_for_the_owner(): void
    {
        $day = $this->day();
        $entry = $this->entry($day);
        $this->actingAs($entry->user);
        foreach (['/training-plans/create', "/training-plans/{$day->training_plan_id}/edit", "/training-plans/{$day->training_plan_id}/days/create", "/training-days/{$day->id}/edit", '/schedule-entries/create', "/schedule-entries/{$entry->id}/edit"] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_day_and_exercise_icons_save_and_can_be_reset_to_automatic(): void
    {
        $plan = $this->plan();
        $payload = [...$this->dayPayload([$this->exercisePayload(['icon' => 'biceps'])]), 'icon' => 'footprints'];
        $this->actingAs($plan->user)->post("/training-plans/{$plan->id}/days", $payload)->assertSessionHasNoErrors();
        $day = $plan->trainingDays()->firstOrFail();
        $exercise = $day->exercises()->firstOrFail();
        $this->assertSame('footprints', $day->icon);
        $this->assertSame('biceps', $exercise->icon);
        $this->get("/training-days/{$day->id}")->assertInertia(fn (Assert $page) => $page->where('day.icon', 'footprints')->where('day.exercises.0.icon', 'biceps'));
        $this->put("/training-days/{$day->id}", [...$this->dayPayload([$this->exercisePayload(['id' => $exercise->id, 'icon' => null])]), 'icon' => null])->assertSessionHasNoErrors();
        $this->assertNull($day->fresh()->icon);
        $this->assertNull($exercise->fresh()->icon);
    }

    public function test_unknown_icons_are_rejected_without_saving_partial_data(): void
    {
        $plan = $this->plan();
        $this->actingAs($plan->user)->post("/training-plans/{$plan->id}/days", [...$this->dayPayload([$this->exercisePayload(['icon' => '<svg>'])]), 'icon' => 'missing'])->assertSessionHasErrors(['icon', 'exercises.0.icon']);
        $this->assertDatabaseCount('training_days', 0);
    }

    public function test_schedule_snapshots_the_icon_and_keeps_it_after_template_changes_and_deletion(): void
    {
        $day = $this->day(null, ['icon' => 'bike']);
        $this->actingAs($day->trainingPlan->user)->post('/schedule-entries', ['training_day_id' => $day->id, 'entry_type' => 'training', 'scheduled_date' => '2026-10-21'])->assertSessionHasNoErrors();
        $entry = ScheduleEntry::firstOrFail();
        $this->assertSame('bike', $entry->icon);
        $this->get('/calendar?date=2026-10-21')->assertInertia(fn (Assert $page) => $page->where('entries.0.icon', 'bike'));
        $this->put("/training-days/{$day->id}", [...$this->dayPayload(), 'icon' => 'mountain'])->assertSessionHasNoErrors();
        $this->assertSame('bike', $entry->fresh()->icon);
        $this->delete("/training-days/{$day->id}")->assertRedirect();
        $this->put("/schedule-entries/{$entry->id}", ['entry_type' => 'training', 'scheduled_date' => '2026-10-22'])->assertSessionHasNoErrors();
        $this->assertSame('bike', $entry->fresh()->icon);
    }

    public function test_dropdown_pairing_persists_on_both_exercises_and_can_be_unlinked(): void
    {
        $day = $this->day();
        $a = $this->exercise($day);
        $b = $this->exercise($day, ['name' => 'Lunge']);
        $this->actingAs($day->trainingPlan->user)->put("/training-days/{$day->id}", $this->dayPayload([
            $this->exercisePayload(['id' => $a->id, 'name' => 'Squat', 'superset_group' => 'A']),
            $this->exercisePayload(['id' => $b->id, 'name' => 'Lunge', 'superset_group' => 'A']),
        ]))->assertSessionHasNoErrors();
        $this->assertSame('A', $a->fresh()->superset_group);
        $this->assertSame('A', $b->fresh()->superset_group);
        $this->put("/training-days/{$day->id}", $this->dayPayload([
            $this->exercisePayload(['id' => $a->id, 'superset_group' => null]),
            $this->exercisePayload(['id' => $b->id, 'superset_group' => null]),
        ]))->assertSessionHasNoErrors();
        $this->assertNull($a->fresh()->superset_group);
        $this->assertNull($b->fresh()->superset_group);
    }
}
