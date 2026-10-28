<?php

namespace Tests\Feature;

use App\Models\CoachSubscriptionPlan;
use App\Models\CoachTraineeRelationship;
use App\Models\ScheduleEntry;
use App\Models\User;
use App\Services\ActiveWorkout;
use App\Services\TrainingPlanDuplicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StabilizationTest extends TestCase
{
    use RefreshDatabase;

    private User $coach;

    private User $otherCoach;

    private User $trainee;

    private User $stranger;

    private CoachTraineeRelationship $relationship;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');
        $this->travelTo(now()->setDate(2026, 10, 28)->setTime(12, 0));
        $this->coach = $this->coach();
        $this->otherCoach = $this->coach();
        $this->trainee = User::factory()->create();
        $this->stranger = User::factory()->create();
        $this->relationship = $this->connect($this->coach, $this->trainee);
        $this->base = '/coach/trainees/'.$this->trainee->id;
    }

    private function coach(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['account_type' => 'coach'])->save();
        $user->coachProfile()->create(['display_name' => 'QA coach']);

        return $user;
    }

    private function connect(User $coach, User $trainee): CoachTraineeRelationship
    {
        $row = CoachTraineeRelationship::create(['coach_user_id' => $coach->id, 'trainee_user_id' => $trainee->id, 'status' => 'active', 'requested_at' => now(), 'approved_at' => now()]);
        $row->subscriptions()->create(['package_name' => 'QA service', 'starts_on' => today(), 'ends_on' => today()->addDays(55), 'status' => 'active']);

        return $row;
    }

    private function assigned(?User $coach = null, ?User $trainee = null)
    {
        $coach ??= $this->coach;
        $trainee ??= $this->trainee;
        $template = $coach->trainingPlans()->create(['name' => 'QA template']);
        $template->forceFill(['workspace_type' => 'coach_template'])->save();
        $day = $template->trainingDays()->create(['title' => 'Strength', 'type' => 'training', 'weekdays' => [0, 1, 2, 3, 4, 5, 6], 'default_time' => '18:00', 'position' => 0]);
        $path = UploadedFile::fake()->image('exercise.jpg')->store('exercise-thumbnails', 'public');
        $day->exercises()->create(['name' => 'Squat', 'sets' => 1, 'reps' => '8-10', 'target_weight' => 40, 'tracking_mode' => 'reps', 'library_key' => 'builtin:Barbell_Full_Squat', 'thumbnail_path' => $path, 'position' => 0]);

        return app(TrainingPlanDuplicator::class)->assign($coach, $trainee, $template, ['start_date' => today()->addDay()->toDateString(), 'end_date' => today()->addDays(10)->toDateString()]);
    }

    private function entry(User $user, array $fields = []): ScheduleEntry
    {
        return $user->scheduleEntries()->create(['title' => 'QA session', 'entry_type' => 'training', 'status' => 'planned', 'scheduled_date' => today()->addDay(), 'scheduled_time' => '18:00', ...$fields]);
    }

    private function props(string $url): array
    {
        return $this->get($url)->assertOk()->viewData('page')['props'];
    }

    public function test_coach_upcoming_excludes_finished_skipped_and_elapsed_sessions(): void
    {
        foreach (['completed', 'skipped'] as $status) {
            $this->entry($this->trainee, ['status' => $status]);
        }
        $this->entry($this->trainee, ['scheduled_date' => today(), 'scheduled_time' => '10:00']);
        $future = $this->entry($this->trainee);
        $today = $this->entry($this->trainee, ['scheduled_date' => today(), 'scheduled_time' => null]);
        $this->entry($this->stranger);
        $this->entry($this->coach);
        $this->actingAs($this->coach);
        $props = $this->props('/coach');
        $this->assertSame([$today->id, $future->id], array_column($props['upcoming'], 'id'));
        $this->assertSame(1, $props['stats']['upcoming']);
    }

    public function test_personal_up_next_excludes_already_completed_and_skipped_workouts(): void
    {
        foreach ([$this->trainee, $this->coach] as $user) {
            $this->entry($user, ['status' => 'completed']);
            $this->entry($user, ['status' => 'skipped']);
            $next = $this->entry($user);
            $rest = $this->entry($user, ['entry_type' => 'rest']);
            $this->actingAs($user);
            $props = $this->props('/dashboard');
            $this->assertSame(2, $props['stats']['upcoming']);
            $this->assertEquals([$next->id, $rest->id], collect($props['upcomingEntries'])->pluck('id')->all());
        }
    }

    public function test_trainee_cards_select_next_session_and_last_training_workout_without_n_plus_one(): void
    {
        $this->entry($this->trainee, ['scheduled_date' => today(), 'scheduled_time' => '10:00']);
        $next = $this->entry($this->trainee, ['scheduled_time' => '14:00']);
        $this->entry($this->trainee, ['scheduled_time' => '18:00']);
        $last = $this->entry($this->trainee, ['status' => 'completed', 'scheduled_date' => today()->subDay()]);
        $this->entry($this->trainee, ['status' => 'completed', 'entry_type' => 'rest', 'scheduled_date' => today()]);
        $this->actingAs($this->coach);
        $measure = function () {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $props = $this->props('/coach/trainees');
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return [$props, $queries];
        };
        [$props, $one] = $measure();
        $this->assertSame($next->id, $props['relationships']['data'][0]['next_session']['id']);
        $this->assertSame($last->id, $props['relationships']['data'][0]['last_workout']['id']);
        for ($i = 0; $i < 11; $i++) {
            $user = User::factory()->create();
            $this->connect($this->coach, $user);
            $this->entry($user);
        }
        [$props, $twelve] = $measure();
        $this->assertCount(12, $props['relationships']['data']);
        $this->assertLessThanOrEqual($one + 2, $twelve, "Twelve trainees used $twelve queries versus $one for one trainee.");
        $detail = $this->props($this->base);
        $this->assertSame($next->id, $detail['nextSession']['id']);
        $this->assertSame($last->id, $detail['recentWorkout']['id']);
    }

    public static function subscriptionStates(): array
    {
        return [['none'], ['paused'], ['ended'], ['expired'], ['upcoming']];
    }

    #[DataProvider('subscriptionStates')]
    public function test_read_only_service_states_block_every_management_route(string $state): void
    {
        $plan = $this->assigned();
        $day = $plan->trainingDays->first();
        $entry = $this->entry($this->trainee, ['training_day_id' => $day->id, 'scheduled_by_user_id' => $this->coach->id]);
        $sub = $this->relationship->subscriptions->first();
        match ($state) {
            'none' => $sub->delete(), 'expired' => $sub->update(['ends_on' => today()->subDay()]),
            'upcoming' => $sub->update(['starts_on' => today()->addDay()]), default => $sub->update(['status' => $state]),
        };
        $this->actingAs($this->coach);
        foreach (['', '/calendar', '/training-history', '/progress', '/progress/exercises', '/training-plans/'.$plan->id] as $suffix) {
            $this->assertFalse($this->props($this->base.$suffix)['trainingContext']['canManage']);
        }
        $this->post($this->base.'/training-plans', ['name' => 'Denied'])->assertForbidden();
        $this->put($this->base.'/training-plans/'.$plan->id, ['name' => 'Denied'])->assertForbidden();
        $this->patch($this->base.'/training-plans/'.$plan->id.'/days/reorder', ['ids' => [$day->id]])->assertForbidden();
        $this->patch($this->base.'/training-days/'.$day->id.'/exercises/reorder', ['ids' => [$day->exercises->first()->id]])->assertForbidden();
        $this->post($this->base.'/assign', ['training_plan_id' => $plan->assignment->source_training_plan_id, 'start_date' => today()->toDateString(), 'end_date' => today()->addDay()->toDateString(), 'confirmed' => true])->assertForbidden();
        $this->post($this->base.'/schedule-entries', ['entry_type' => 'training', 'training_day_id' => $day->id, 'scheduled_date' => today()->addDay()->toDateString()])->assertForbidden();
        $this->delete($this->base.'/schedule-entries/'.$entry->id)->assertForbidden();
        $this->assertSame('QA template', $plan->fresh()->name);
    }

    public function test_cross_coach_and_stranger_id_substitution_cannot_write_or_read_unrelated_data(): void
    {
        $this->connect($this->otherCoach, $this->trainee);
        $mine = $this->assigned();
        $other = $this->assigned($this->otherCoach);
        $day = $other->trainingDays->first();
        $entry = $this->entry($this->trainee, ['training_day_id' => $day->id, 'scheduled_by_user_id' => $this->otherCoach->id]);
        $foreign = $this->entry($this->stranger);
        $this->actingAs($this->coach);
        $this->get('/coach/trainees/'.$this->stranger->id.'/calendar')->assertNotFound();
        $this->get($this->base.'/schedule-entries/'.$foreign->id)->assertForbidden();
        $this->delete($this->base.'/schedule-entries/'.$entry->id)->assertForbidden();
        $this->get($this->base.'/training-plans/'.$other->id)->assertNotFound();
        $this->get($this->base.'/training-days/'.$day->id)->assertNotFound();
        $this->post($this->base.'/training-days/'.$mine->trainingDays->first()->id.'/duplicate', ['confirmed' => true, 'training_plan_id' => $other->id])->assertNotFound();
        $this->post($this->base.'/assignments/'.$other->assignment->id.'/end', ['confirmed' => true])->assertNotFound();
        $otherRelation = $other->assignment->relationship;
        $this->patch('/coach/subscriptions/'.$otherRelation->subscriptions->first()->id, ['action' => 'pause'])->assertForbidden();
        $this->post('/coach/subscriptions', ['relationship_id' => $otherRelation->id, 'package_name' => 'Denied', 'starts_on' => today()->toDateString(), 'open_ended' => true])->assertNotFound();
        $this->post('/my-coaching/relationships/'.$otherRelation->id, ['action' => 'end', 'confirmed' => true])->assertForbidden();
        $this->actingAs($this->stranger)->get('/training-plans/'.$mine->id)->assertForbidden();
        $this->get('/training-history/'.$entry->id)->assertForbidden();
        $this->get('/progress/exercises?exercise=builtin:Barbell_Full_Squat')->assertNotFound();
    }

    public function test_all_actual_result_mutations_are_owner_only_even_with_active_subscription(): void
    {
        $plan = $this->assigned();
        $entry = $this->entry($this->trainee, ['training_day_id' => $plan->trainingDays->first()->id, 'scheduled_date' => today()->subDay()]);
        app(ActiveWorkout::class)->start($entry);
        $exercise = $entry->sessionExercises()->first();
        $set = $exercise->sets()->first();
        $weight = $this->trainee->bodyWeightEntries()->create(['weight_kg' => 80, 'recorded_date' => today()]);
        $before = $entry->fresh()->getAttributes();
        foreach ([$this->coach, $this->otherCoach, $this->stranger] as $actor) {
            $this->actingAs($actor);
            $this->patch('/session-exercises/'.$exercise->id.'/status', ['status' => 'skipped'])->assertForbidden();
            $this->post('/session-exercises/'.$exercise->id.'/sets')->assertForbidden();
            $this->patch('/session-sets/'.$set->id, ['actual_reps' => 99, 'actual_weight_kg' => 99, 'completed' => true])->assertForbidden();
            $this->delete('/session-sets/'.$set->id, ['confirmed' => true])->assertForbidden();
            $this->delete('/body-weight/'.$weight->id)->assertForbidden();
            $this->put('/schedule-entries/'.$entry->id, ['details_only' => true, 'duration_minutes' => 99, 'calories_burned' => 999])->assertForbidden();
            foreach (['start', 'finish', 'discard'] as $action) {
                $this->post('/schedule-entries/'.$entry->id.'/workout/'.$action, ['confirmed' => true])->assertForbidden();
            }
        }
        $this->assertSame($before, $entry->fresh()->getAttributes());
        $this->assertNull($set->fresh()->actual_reps);
        $this->assertNotNull($weight->fresh());
    }

    public function test_deleting_coach_preserves_assigned_plan_history_sets_media_and_body_progress(): void
    {
        $plan = $this->assigned();
        $day = $plan->trainingDays->first();
        $entry = $this->entry($this->trainee, ['training_day_id' => $day->id, 'scheduled_by_user_id' => $this->coach->id, 'scheduled_date' => today()->subDay()]);
        app(ActiveWorkout::class)->start($entry);
        $set = $entry->sessionExercises()->first()->sets()->first();
        $this->actingAs($this->trainee)->patch('/session-sets/'.$set->id, ['actual_reps' => 10, 'actual_weight_kg' => 40, 'completed' => true])->assertRedirect();
        $this->post('/schedule-entries/'.$entry->id.'/workout/finish')->assertRedirect();
        $snapshot = $entry->fresh()->workout_snapshot;
        $this->coach->delete();
        $this->assertNotNull($plan->fresh());
        $this->assertNotNull($set->fresh());
        $this->assertNull($entry->fresh()->scheduled_by_user_id);
        $this->assertSame($snapshot, $entry->fresh()->workout_snapshot);
        Storage::disk('public')->assertExists($day->exercises->first()->thumbnail_path);
        foreach ($entry->fresh()->snapshotThumbnailPaths() as $path) {
            Storage::disk('public')->assertExists($path);
        }
        $this->get('/training-history/'.$entry->id)->assertOk();
        $this->get('/progress/exercises?exercise=builtin:Barbell_Full_Squat')->assertOk();
        $this->get('/training-plans/'.$plan->id)->assertOk();
    }

    public function test_deleted_or_archived_package_cannot_create_service_and_existing_snapshot_survives(): void
    {
        $package = CoachSubscriptionPlan::create(['coach_user_id' => $this->coach->id, 'name' => 'Eight week', 'duration_days' => 56, 'active' => true]);
        $sub = $this->relationship->subscriptions->first();
        $sub->update(['coach_subscription_plan_id' => $package->id, 'package_name' => $package->name]);
        $package->delete();
        $this->assertNull($sub->fresh()->coach_subscription_plan_id);
        $this->assertSame('Eight week', $sub->fresh()->package_name);
        $sub->update(['status' => 'ended']);
        $this->actingAs($this->coach)->post('/coach/subscriptions', ['relationship_id' => $this->relationship->id, 'package_id' => $package->id, 'starts_on' => today()->toDateString()])->assertNotFound();
        $package = CoachSubscriptionPlan::create(['coach_user_id' => $this->coach->id, 'name' => 'Archived', 'duration_days' => 56, 'active' => false]);
        $this->post('/coach/subscriptions', ['relationship_id' => $this->relationship->id, 'package_id' => $package->id, 'starts_on' => today()->toDateString()])->assertNotFound();
    }

    public function test_qr_generation_and_token_views_are_rate_limited(): void
    {
        $this->actingAs($this->trainee);
        for ($i = 0; $i < 5; $i++) {
            $qr = $this->postJson('/my-coaching/qr')->assertOk()->json();
        }
        $this->postJson('/my-coaching/qr')->assertTooManyRequests();
        $path = parse_url($qr['url'], PHP_URL_PATH);
        $this->actingAs($this->coach);
        for ($i = 0; $i < 20; $i++) {
            $this->get($path)->assertOk();
        }
        $this->get($path)->assertTooManyRequests();
        $this->assertSame('active', $this->relationship->fresh()->status);
    }

    public function test_coaching_date_only_fields_do_not_shift_to_the_previous_day_when_serialized(): void
    {
        $next = $this->entry($this->trainee);
        $this->actingAs($this->coach);
        foreach (['/coach/trainees', $this->base, $this->base.'/assign'] as $path) {
            $props = json_decode(json_encode($this->props($path)), true);
            $sub = $path === '/coach/trainees' ? $props['relationships']['data'][0]['subscription'] : $props['subscription'];
            $this->assertSame('2026-10-28', $sub['starts_on']);
            $this->assertSame('2026-12-22', $sub['ends_on']);
            if ($path === '/coach/trainees') {
                $this->assertSame('2026-10-29', $props['relationships']['data'][0]['next_session']['scheduled_date']);
            }
        }
        $this->actingAs($this->trainee);
        $props = json_decode(json_encode($this->props('/my-coaching')), true);
        $this->assertSame('2026-10-28', $props['relationships'][0]['subscriptions'][0]['starts_on']);
        $this->assertSame('2026-12-22', $props['relationships'][0]['subscriptions'][0]['ends_on']);
    }
}
