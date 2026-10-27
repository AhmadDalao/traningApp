<?php

namespace Tests\Feature;

use App\Models\ExerciseLibraryItem;
use App\Models\ScheduleEntry;
use App\Models\TrainingPlan;
use App\Models\User;
use App\Services\ExerciseLibrary;
use App\Services\PlanSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExerciseLibraryAndPlanScheduleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private TrainingPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 10, 26)->setTime(12, 0));
        Storage::fake('public');
        $this->user = User::factory()->create();
        $this->plan = $this->user->trainingPlans()->create(['name' => 'Weekly plan', 'start_date' => '2026-10-20', 'end_date' => '2026-11-08']);
        $this->actingAs($this->user);
    }

    private function exercise(array $extra = []): array
    {
        return [...['name' => 'Bench Press', 'sets' => 3, 'reps' => '8–12', 'icon' => 'weight'], ...$extra];
    }

    private function saveDay(array $exercise, array $extra = [])
    {
        return $this->post('/training-plans/'.$this->plan->id.'/days', [...['title' => 'Strength', 'type' => 'training', 'focus_area' => 'Chest', 'exercises' => [$exercise]], ...$extra]);
    }

    public function test_builtin_library_selection_copies_photo_and_keeps_existing_prescription(): void
    {
        $catalog = json_decode(file_get_contents(resource_path('data/exercise-library.json')), true);
        $item = collect($catalog)->first(fn ($item) => $item['image'] !== null);
        $this->saveDay($this->exercise(['library_key' => $item['key'], 'name' => $item['name'], 'icon' => $item['icon'], 'target_weight' => 42.5]))->assertSessionHasNoErrors();
        $exercise = $this->plan->trainingDays()->first()->exercises()->first();
        $this->assertSame($item['key'], $exercise->library_key);
        $this->assertSame('42.50', $exercise->target_weight);
        $this->assertSame('8–12', $exercise->reps);
        Storage::disk('public')->assertExists($exercise->thumbnail_path);
        $this->assertSame(file_get_contents(public_path('exercise-library/'.$item['image'])), Storage::disk('public')->get($exercise->thumbnail_path));
    }

    public function test_custom_exercise_is_private_reusable_and_has_independent_media_copies(): void
    {
        $this->saveDay($this->exercise([
            'save_to_library' => true,
            'thumbnail' => UploadedFile::fake()->image('movement.jpg', 600, 400),
            'custom_icon' => UploadedFile::fake()->image('icon.png', 128, 128),
        ]))->assertSessionHasNoErrors();
        $custom = ExerciseLibraryItem::sole();
        $day = $this->plan->trainingDays()->first();
        $exercise = $day->exercises()->first();
        $this->assertSame('custom:'.$custom->id, $exercise->library_key);
        $this->assertNotSame($custom->thumbnail_path, $exercise->thumbnail_path);
        $this->assertNotSame($custom->custom_icon_path, $exercise->custom_icon_path);
        $this->get('/training-plans/'.$this->plan->id.'/days/create')->assertInertia(fn (Assert $page) => $page->has('customExercises', 1)->where('customExercises.0.name', 'Bench Press'));
        $this->saveDay($this->exercise(['library_key' => 'custom:'.$custom->id]))->assertSessionHasNoErrors();
        $otherExercise = $this->plan->trainingDays()->reorder()->latest('id')->first()->exercises()->first();
        $this->assertNotSame($otherExercise->thumbnail_path, $exercise->thumbnail_path);
        Storage::disk('public')->assertExists($otherExercise->custom_icon_path);
        $this->delete('/training-days/'.$day->id)->assertRedirect();
        Storage::disk('public')->assertMissing($exercise->thumbnail_path);
        Storage::disk('public')->assertMissing($exercise->custom_icon_path);
        Storage::disk('public')->assertExists([$custom->thumbnail_path, $custom->custom_icon_path, $otherExercise->thumbnail_path, $otherExercise->custom_icon_path]);
        $other = User::factory()->create();
        $otherPlan = $other->trainingPlans()->create(['name' => 'Private']);
        $this->actingAs($other)->get('/training-plans/'.$otherPlan->id.'/days/create')->assertInertia(fn (Assert $page) => $page->has('customExercises', 0));
        $this->post('/training-plans/'.$otherPlan->id.'/days', ['title' => 'No access', 'type' => 'training', 'exercises' => [$this->exercise(['library_key' => 'custom:'.$custom->id])]])->assertSessionHasErrors('exercises.0.library_key');
    }

    public function test_icons_are_validated_and_unknown_library_keys_do_not_save_partial_data(): void
    {
        foreach ([UploadedFile::fake()->image('wide.png', 128, 64), UploadedFile::fake()->image('tiny.png', 32, 32), UploadedFile::fake()->image('huge.png', 600, 600), UploadedFile::fake()->image('heavy.png', 128, 128)->size(513), UploadedFile::fake()->create('icon.svg', 1, 'image/svg+xml')] as $file) {
            $this->saveDay($this->exercise(['custom_icon' => $file]))->assertSessionHasErrors('exercises.0.custom_icon');
        }
        foreach (['builtin:missing', '../../.env', 'custom:9999'] as $key) {
            $this->saveDay($this->exercise(['library_key' => $key]))->assertSessionHasErrors('exercises.0.library_key');
        }
        $this->assertDatabaseCount('training_days', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_completed_history_retains_custom_icon_after_plan_deletion(): void
    {
        $this->saveDay($this->exercise(['custom_icon' => UploadedFile::fake()->image('icon.png', 128, 128)]))->assertSessionHasNoErrors();
        $day = $this->plan->trainingDays()->first();
        $original = $day->exercises()->first()->custom_icon_path;
        $entry = $this->user->scheduleEntries()->create(['training_day_id' => $day->id, 'title' => 'History', 'entry_type' => 'training', 'scheduled_date' => '2026-10-25', 'status' => 'planned']);
        $this->patch('/schedule-entries/'.$entry->id.'/status', ['status' => 'completed'])->assertSessionHasNoErrors();
        $saved = $entry->fresh()->workout_snapshot['exercises'][0]['custom_icon_path'];
        $this->assertNotSame($original, $saved);
        $this->delete('/training-plans/'.$this->plan->id)->assertRedirect();
        Storage::disk('public')->assertMissing($original);
        Storage::disk('public')->assertExists($saved);
        $this->get('/training-history/'.$entry->id)->assertInertia(fn (Assert $page) => $page->where('workout.exercises.0.custom_icon_url', Storage::disk('public')->url($saved)));
        $this->delete('/schedule-entries/'.$entry->id)->assertRedirect();
        Storage::disk('public')->assertMissing($saved);
    }

    public function test_plan_dates_and_weekday_preferences_save_without_scheduling_anything(): void
    {
        $this->put('/training-plans/'.$this->plan->id, ['name' => 'Dates', 'start_date' => '2026-10-26', 'end_date' => '2026-11-30'])->assertSessionHasNoErrors();
        $this->saveDay($this->exercise(), ['weekdays' => [0, 2, 6], 'default_time' => '18:30'])->assertSessionHasNoErrors();
        $day = $this->plan->trainingDays()->first();
        $this->assertSame([0, 2, 6], $day->weekdays);
        $this->assertStringStartsWith('18:30', $day->default_time);
        $this->assertDatabaseCount('schedule_entries', 0);
        foreach ([['start_date' => '2026-11-30', 'end_date' => '2026-10-26'], ['start_date' => '2026-10-26'], ['end_date' => '2026-11-30']] as $dates) {
            $this->put('/training-plans/'.$this->plan->id, ['name' => 'Invalid', ...$dates])->assertSessionHasErrors();
        }
        $this->saveDay($this->exercise(), ['weekdays' => [7]])->assertSessionHasErrors('weekdays.0');
        $this->saveDay($this->exercise(), ['weekdays' => [1, 1]])->assertSessionHasErrors('weekdays.0');
    }

    public function test_schedule_preview_matches_weekdays_and_dates_and_adds_once_without_touching_history(): void
    {
        $day = $this->plan->trainingDays()->create(['title' => 'Sunday and Tuesday', 'type' => 'training', 'weekdays' => [0, 2], 'default_time' => '18:30']);
        $rest = $this->plan->trainingDays()->create(['title' => 'Saturday recovery', 'type' => 'rest', 'weekdays' => [6]]);
        $entry = $this->user->scheduleEntries()->create(['training_day_id' => $day->id, 'title' => 'Already booked', 'entry_type' => 'training', 'scheduled_date' => '2026-10-27', 'status' => 'completed', 'workout_snapshot' => ['exercises' => []]]);
        $before = $entry->fresh()->getRawOriginal();
        $preview = app(PlanSchedule::class)->preview($this->plan->fresh());
        $this->assertSame(['2026-10-27', '2026-10-31', '2026-11-01', '2026-11-03', '2026-11-07', '2026-11-08'], array_column($preview['sessions'], 'scheduled_date'));
        $this->assertSame(5, $preview['new_count']);
        $this->assertSame(1, $preview['existing_count']);
        $this->get('/training-plans/'.$this->plan->id.'/schedule-preview')->assertInertia(fn (Assert $page) => $page->component('TrainingPlans/Schedule')->has('preview.sessions', 6)->where('preview.new_count', 5));
        $this->assertDatabaseCount('schedule_entries', 1);
        $this->post('/training-plans/'.$this->plan->id.'/schedule', ['signature' => $preview['signature']])->assertSessionHasNoErrors()->assertRedirect('/training-plans/'.$this->plan->id);
        $this->assertDatabaseCount('schedule_entries', 6);
        $this->assertSame($before, $entry->fresh()->getRawOriginal());
        $this->assertSame(2, ScheduleEntry::where('entry_type', 'rest')->count());
        $this->post('/training-plans/'.$this->plan->id.'/schedule', ['signature' => $preview['signature']])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('schedule_entries', 6);
        $this->assertSame(0, app(PlanSchedule::class)->preview($this->plan->fresh())['new_count']);
    }

    public function test_schedule_rejects_stale_previews_foreign_plans_and_outside_ranges(): void
    {
        $day = $this->plan->trainingDays()->create(['title' => 'Tuesday', 'type' => 'training', 'weekdays' => [2]]);
        $preview = app(PlanSchedule::class)->preview($this->plan->fresh());
        $day->update(['weekdays' => [3]]);
        $this->post('/training-plans/'.$this->plan->id.'/schedule', ['signature' => $preview['signature']])->assertSessionHasErrors('schedule');
        $this->assertDatabaseCount('schedule_entries', 0);
        $this->actingAs(User::factory()->create())->get('/training-plans/'.$this->plan->id.'/schedule-preview')->assertForbidden();
        $this->post('/training-plans/'.$this->plan->id.'/schedule', ['signature' => $preview['signature']])->assertForbidden();
        $this->actingAs($this->user);
        $this->plan->update(['start_date' => null, 'end_date' => null]);
        $this->assertSame([], app(PlanSchedule::class)->preview($this->plan->fresh())['sessions']);
        $this->plan->update(['start_date' => '2026-09-01', 'end_date' => '2026-09-30']);
        $this->assertSame([], app(PlanSchedule::class)->preview($this->plan->fresh())['sessions']);
        $this->plan->update(['start_date' => '2026-10-27', 'end_date' => '2026-10-27']);
        $day->update(['weekdays' => [2]]);
        $this->assertCount(1, app(PlanSchedule::class)->preview($this->plan->fresh())['sessions']);
    }

    public function test_preview_keeps_a_generated_session_that_was_moved_outside_the_plan_period(): void
    {
        $this->plan->trainingDays()->create(['title' => 'Tuesday', 'type' => 'training', 'weekdays' => [2]]);
        $preview = app(PlanSchedule::class)->preview($this->plan->fresh());
        $this->post('/training-plans/'.$this->plan->id.'/schedule', ['signature' => $preview['signature']])->assertSessionHasNoErrors();
        $entry = $this->user->scheduleEntries()->orderBy('scheduled_date')->first();
        $entry->update(['scheduled_date' => '2026-12-20', 'title' => 'Moved by me']);
        $updated = app(PlanSchedule::class)->preview($this->plan->fresh());
        $this->assertSame(0, $updated['new_count']);
        $this->assertSame(2, $updated['existing_count']);
        $this->assertSame('2026-12-20', $updated['sessions'][0]['existing_date']);
        $this->assertSame($preview['signature'], $updated['signature']);
        $this->post('/training-plans/'.$this->plan->id.'/schedule', ['signature' => $updated['signature']])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('schedule_entries', 2);
        $this->assertSame('Moved by me', $entry->fresh()->title);
        $this->assertSame('2026-12-20', $entry->fresh()->scheduled_date->toDateString());
    }
}
