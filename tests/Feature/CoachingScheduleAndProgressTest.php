<?php

namespace Tests\Feature;

use App\Models\CoachTraineeRelationship;
use App\Models\ScheduleEntry;
use App\Models\TrainingDay;
use App\Models\TrainingPlan;
use App\Models\User;
use App\Services\ExerciseProgression;
use App\Services\PlanSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CoachingScheduleAndProgressTest extends TestCase
{
    use RefreshDatabase;

    private User $coach;

    private User $trainee;

    private TrainingPlan $plan;

    private TrainingDay $day;

    private CoachTraineeRelationship $relationship;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');
        $this->travelTo(now()->setDate(2026, 10, 28)->setTime(12, 0));
        $this->coach = User::factory()->create();
        $this->coach->forceFill(['account_type' => 'coach'])->save();
        $this->coach->coachProfile()->create(['display_name' => 'Coach']);
        $this->trainee = User::factory()->create();
        $this->relationship = CoachTraineeRelationship::create(['coach_user_id' => $this->coach->id, 'trainee_user_id' => $this->trainee->id, 'status' => 'active', 'requested_at' => now(), 'approved_at' => now()]);
        $sub = $this->relationship->subscriptions()->create(['package_name' => 'Training', 'starts_on' => today(), 'ends_on' => today()->addDays(7), 'status' => 'active']);
        $this->plan = $this->trainee->trainingPlans()->create(['name' => 'Assigned', 'start_date' => today()->addDay(), 'end_date' => today()->addDays(10)]);
        $this->plan->forceFill(['workspace_type' => 'coach_assigned'])->save();
        $this->relationship->assignments()->create(['coach_subscription_id' => $sub->id, 'assigned_training_plan_id' => $this->plan->id, 'assigned_at' => now(), 'status' => 'active']);
        $this->day = $this->plan->trainingDays()->create(['title' => 'Training', 'type' => 'training', 'weekdays' => [0, 1, 2, 3, 4, 5, 6], 'default_time' => '18:00', 'position' => 0]);
        $this->day->exercises()->create(['name' => 'Squat', 'sets' => 3, 'reps' => '8-10', 'target_weight' => 40, 'tracking_mode' => 'reps', 'library_key' => 'builtin:Barbell_Full_Squat', 'position' => 0]);
        $this->base = '/coach/trainees/'.$this->trainee->id;
        $this->actingAs($this->coach);
    }

    private function props(string $path): array
    {
        return $this->get($path)->assertOk()->viewData('page')['props'];
    }

    private function preview(): array
    {
        return $this->props($this->base.'/training-plans/'.$this->plan->id.'/schedule-preview')['preview'];
    }

    private function apply(array $preview, string $action = 'add', bool $remove = false)
    {
        return $this->post($this->base.'/training-plans/'.$this->plan->id.'/schedule', ['signature' => $preview['signature'], 'action' => $action, 'remove_obsolete' => $remove, 'confirmed' => true]);
    }

    private function entry(array $fields = []): ScheduleEntry
    {
        return $this->trainee->scheduleEntries()->create(['training_day_id' => $this->day->id, 'title' => 'Future', 'entry_type' => 'training', 'status' => 'planned', 'scheduled_date' => today()->addDay(), 'scheduled_time' => '18:00', 'scheduled_by_user_id' => $this->coach->id, ...$fields]);
    }

    private function workout(int $days, float $weight = 40): ScheduleEntry
    {
        $entry = $this->entry(['scheduled_date' => today()->subDays($days), 'status' => 'completed', 'completed_at' => now(), 'workout_snapshot' => ['version' => 1, 'training_plan_id' => $this->plan->id, 'exercises' => []]]);
        $template = $this->day->exercises->first();
        $exercise = $entry->sessionExercises()->create(['source_training_exercise_id' => $template->id, 'source_exercise_key' => $template->library_key, 'name' => 'Squat', 'tracking_mode' => 'reps', 'planned_sets' => 3, 'planned_reps_text' => '8-10', 'planned_weight_kg' => 40, 'position' => 0, 'status' => 'completed']);
        foreach ([1, 2, 3] as $n) {
            $exercise->sets()->create(['set_number' => $n, 'actual_reps' => 10, 'actual_weight_kg' => $weight, 'completed_at' => now()]);
        }

        return $entry;
    }

    public function test_schedule_preview_clips_additions_visibly_and_keeps_trainee_ownership(): void
    {
        $preview = $this->preview();
        $this->assertCount(7, $preview['add']);
        $this->assertCount(3, $preview['excluded']);
        $this->apply($preview)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('schedule_entries', 7);
        foreach (ScheduleEntry::all() as $entry) {
            $this->assertSame($this->trainee->id, $entry->user_id);
            $this->assertSame($this->coach->id, $entry->scheduled_by_user_id);
            $this->assertTrue($entry->schedule_managed);
        }
        $this->apply($preview)->assertSessionHasErrors('schedule');
        $this->apply($this->preview())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('schedule_entries', 7);
        $this->actingAs($this->trainee)->get('/calendar')->assertInertia(fn (Assert $p) => $p->has('entries', 7));
    }

    public function test_personal_other_coach_and_legacy_bookings_are_protected_and_block_duplicates(): void
    {
        foreach ([$this->trainee->id, User::factory()->create()->id, null] as $offset => $by) {
            $date = today()->addDays($offset + 1);
            $this->entry(['scheduled_date' => $date, 'scheduled_by_user_id' => $by, 'schedule_managed' => true, 'plan_occurrence_key' => 'plan:'.$this->plan->id.':'.$this->day->id.':'.$date->toDateString()]);
        }
        $preview = $this->preview();
        $this->assertCount(3, $preview['protected']);
        $this->assertCount(4, $preview['add']);
        $this->apply($preview, 'remove')->assertSessionHasNoErrors();
        $this->assertDatabaseCount('schedule_entries', 3);
        $this->apply($this->preview())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('schedule_entries', 7);
        $this->apply($this->preview(), 'remove')->assertSessionHasNoErrors();
        $this->assertDatabaseCount('schedule_entries', 3);
    }

    public static function protectedStates(): array
    {
        return [['completed'], ['skipped'], ['started'], ['snapshot'], ['structured'], ['past'], ['personal'], ['other'], ['detached']];
    }

    #[DataProvider('protectedStates')]
    public function test_coach_cannot_edit_or_delete_protected_sessions(string $state): void
    {
        $entry = $this->entry();
        match ($state) {
            'completed','skipped' => $entry->update(['status' => $state]),'started' => $entry->update(['started_at' => now()]),'snapshot' => $entry->update(['workout_snapshot' => ['exercises' => []]]),'structured' => $entry->sessionExercises()->create(['name' => 'Saved', 'tracking_mode' => 'reps', 'planned_sets' => 1, 'position' => 0]),'past' => $entry->update(['scheduled_date' => today()->subDay()]),'personal' => $entry->update(['scheduled_by_user_id' => $this->trainee->id]),'other' => $entry->update(['scheduled_by_user_id' => User::factory()->create()->id]),'detached' => $entry->update(['training_day_id' => null])
        };
        $before = $entry->fresh()->getAttributes();
        $this->put($this->base.'/schedule-entries/'.$entry->id, ['entry_type' => 'training', 'training_day_id' => $this->day->id, 'scheduled_date' => today()->addDays(2)->toDateString()])->assertForbidden();
        $this->delete($this->base.'/schedule-entries/'.$entry->id)->assertForbidden();
        $this->assertSame($before, $entry->fresh()->getAttributes());
        $this->get($this->base.'/schedule-entries/'.$entry->id)->assertInertia(fn (Assert $p) => $p->where('canManageSession', false));
    }

    public function test_single_session_and_manual_edit_do_not_expose_actual_fields_or_bulk_control(): void
    {
        $data = ['entry_type' => 'training', 'training_day_id' => $this->day->id, 'scheduled_date' => today()->addDay()->toDateString(), 'scheduled_time' => '17:00'];
        $this->post($this->base.'/schedule-entries', $data)->assertRedirect()->assertSessionHasNoErrors();
        $entry = ScheduleEntry::firstOrFail();
        $this->assertSame($this->coach->id, $entry->scheduled_by_user_id);
        $this->assertSame($this->trainee->id, $entry->user_id);
        $this->put($this->base.'/schedule-entries/'.$entry->id, [...$data, 'scheduled_time' => '19:00'])->assertSessionHasNoErrors();
        $this->assertSame('19:00', substr($entry->fresh()->scheduled_time, 0, 5));
        $this->assertCount(1, $this->preview()['protected']);
        $this->put($this->base.'/schedule-entries/'.$entry->id, [...$data, 'status' => 'completed', 'calories_burned' => 400])->assertSessionHasErrors(['status', 'calories_burned']);
        $this->assertSame('planned', $entry->fresh()->status);
        $this->post($this->base.'/schedule-entries', [...$data, 'scheduled_date' => today()->addDays(20)->toDateString()])->assertSessionHasErrors('scheduled_date');
        $this->delete($this->base.'/schedule-entries/'.$entry->id)->assertRedirect();
        $this->assertNull($entry->fresh());
    }

    public function test_stale_preview_status_pause_and_disconnect_are_rechecked(): void
    {
        $this->apply($this->preview());
        $preview = $this->preview();
        $entry = ScheduleEntry::firstOrFail();
        $entry->update(['status' => 'completed']);
        $this->apply($preview, 'remove')->assertSessionHasErrors('schedule');
        $this->assertDatabaseCount('schedule_entries', 7);
        $preview = $this->preview();
        $this->relationship->subscriptions()->update(['status' => 'paused']);
        $this->apply($preview, 'remove')->assertForbidden();
        $this->relationship->update(['status' => 'ended']);
        $this->apply($preview, 'remove')->assertNotFound();
        $this->assertDatabaseCount('schedule_entries', 7);
    }

    public function test_coach_cannot_use_personal_preview_token_and_owner_can_manage_their_own_calendar(): void
    {
        $owner = app(PlanSchedule::class)->preview($this->plan->fresh());
        $this->apply($owner)->assertSessionHasErrors('schedule');
        $this->assertDatabaseCount('schedule_entries', 0);
        $this->actingAs($this->trainee)->post('/training-plans/'.$this->plan->id.'/schedule', ['signature' => $owner['signature'], 'action' => 'add', 'confirmed' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('schedule_entries', 10);
        $this->actingAs($this->coach);
        $this->assertCount(10, $this->preview()['protected']);
    }

    public function test_history_progress_and_body_data_match_the_owner_without_write_capabilities(): void
    {
        $this->workout(1);
        $this->workout(2, 42.5);
        $this->trainee->bodyWeightEntries()->create(['weight_kg' => 80, 'recorded_date' => today()]);
        $this->actingAs($this->trainee);
        $personal = $this->props('/training-history');
        $body = $this->props('/progress');
        $progress = $this->props('/progress/exercises');
        $this->actingAs($this->coach);
        $coach = $this->props($this->base.'/training-history');
        $coachBody = $this->props($this->base.'/progress');
        $coachProgress = $this->props($this->base.'/progress/exercises');
        $this->assertEquals($personal['summary'], $coach['summary']);
        $this->assertEquals($personal['entries']['data'], $coach['entries']['data']);
        $this->assertSame('10', $coach['filters']['per_page']);
        $this->assertEquals($body['summary'], $coachBody['summary']);
        $this->assertEquals($progress['exercises']['data'][0]['records'], $coachProgress['exercises']['data'][0]['records']);
        $this->assertStringStartsWith($this->base.'/progress/exercises', $coachProgress['exercises']['data'][0]['url']);
        $this->assertFalse($coach['trainingContext']['templates']);
        $this->assertSame($this->coach->id, $coach['auth']['user']['id']);
        $today = $this->props($this->base.'/training-history?period=today');
        $this->assertEquals(0, $today['entries']['total']);
        $this->relationship->update(['status' => 'ended']);
        foreach (['/progress', '/progress/exercises', '/training-history', '/calendar'] as $path) {
            $this->get($this->base.$path)->assertNotFound();
        }
    }

    public function test_actual_workout_and_body_weight_mutations_remain_owner_only(): void
    {
        $entry = $this->workout(1);
        $set = $entry->sessionExercises->first()->sets->first();
        $weight = $this->trainee->bodyWeightEntries()->create(['weight_kg' => 80, 'recorded_date' => today()]);
        foreach (['/workout/start', '/workout/finish', '/workout/discard'] as $path) {
            $this->post('/schedule-entries/'.$entry->id.$path, ['confirmed' => true])->assertForbidden();
        }
        $this->patch('/schedule-entries/'.$entry->id.'/status', ['status' => 'planned'])->assertForbidden();
        $this->patch('/session-sets/'.$set->id, ['actual_reps' => 99])->assertForbidden();
        $this->patch('/schedule-entries/'.$entry->id.'/weights', ['weights' => []])->assertForbidden();
        $this->put('/schedule-entries/'.$entry->id, ['details_only' => true, 'duration_minutes' => 1])->assertForbidden();
        $this->put('/body-weight/'.$weight->id, ['weight_kg' => 10, 'recorded_date' => today()->toDateString()])->assertForbidden();
        $this->assertEquals(10, $set->fresh()->actual_reps);
        $this->assertEquals(80, $weight->fresh()->weight_kg);
        $this->assertSame('completed', $entry->fresh()->status);
    }

    public function test_coach_progression_applies_only_to_own_assignment_and_tokens_cannot_cross_contexts(): void
    {
        $this->workout(2);
        $this->workout(1);
        $template = $this->day->exercises->first();
        $path = $this->base.'/progress/exercises?'.http_build_query(['exercise' => $template->library_key, 'template' => $template->id]);
        $suggestion = $this->props($path)['progression']['suggestion'];
        $this->assertTrue($suggestion['can_apply']);
        $personal = app(ExerciseProgression::class)->forTemplate($this->trainee, $template->fresh());
        $this->patch($this->base.'/training-exercises/'.$template->id.'/progression', ['token' => $personal['token'], 'value' => '42.5'])->assertSessionHasErrors('progression');
        $this->patch($this->base.'/training-exercises/'.$template->id.'/progression', ['token' => $suggestion['token'], 'value' => '42.5'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals(42.5, $template->fresh()->target_weight);
        $this->patch('/training-exercises/'.$template->id.'/progression', ['token' => $suggestion['token'], 'value' => '45'])->assertForbidden();
        $this->relationship->subscriptions()->update(['status' => 'paused']);
        $this->assertFalse($this->props($path)['progression']['suggestion']['can_apply']);
        $this->assertNull($this->props($path)['progression']['suggestion']['token']);
        $this->patch($this->base.'/training-exercises/'.$template->id.'/progression', ['token' => $suggestion['token'], 'value' => '45'])->assertForbidden();
    }

    public function test_coach_metrics_preserve_unweighted_reps_and_current_completion_status(): void
    {
        $entry = $this->workout(1, 0);
        $sets = $entry->sessionExercises->first()->sets;
        $sets[0]->update(['actual_weight_kg' => null]);
        $path = $this->base.'/progress/exercises?exercise=builtin:Barbell_Full_Squat&mode=reps';
        $props = $this->props($path);
        $this->assertFalse($props['exercise']['weighted']);
        $row = $props['history']['data'][0];
        $this->assertSame(30, $row['total_reps']);
        foreach (['heaviest_weight', 'best_set', 'weighted_volume'] as $field) {
            $this->assertNull($row[$field]);
        }
        foreach (['planned', 'skipped'] as $status) {
            $entry->update(['status' => $status]);
            $this->get($path)->assertNotFound();
        }
        $sets[0]->update(['actual_reps' => 15, 'actual_weight_kg' => 12.5]);
        $entry->update(['status' => 'completed']);
        $row = $this->props($path)['history']['data'][0];
        $this->assertEquals(35, $row['total_reps']);
        $this->assertEquals(12.5, $row['heaviest_weight']);
        $this->assertEquals(187.5, $row['weighted_volume']);
    }
}
