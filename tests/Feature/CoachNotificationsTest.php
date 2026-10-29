<?php

namespace Tests\Feature;

use App\Models\CoachTraineeRelationship;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\TrainingPlan;
use App\Models\User;
use App\Models\WebPushSubscription;
use App\Services\Notifications\CoachNotifications;
use App\Services\Notifications\PushDeliveries;
use App\Services\ProgramControl;
use App\Services\ProgramSchedule;
use App\Services\TrainingPlanDuplicator;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CoachNotificationsTest extends TestCase
{
    use DatabaseMigrations;

    private function setupCoaching(): array
    {
        $this->withoutVite();
        Storage::fake('public');
        $coach = User::factory()->create(['account_type' => 'coach']);
        $coach->coachProfile()->create(['display_name' => 'Coach']);
        $trainee = User::factory()->create();
        foreach ([$coach, $trainee] as $user) {
            NotificationPreference::create(['user_id' => $user->id, 'coach_updates' => true]);
            WebPushSubscription::create(['user_id' => $user->id, 'device_hash' => hash('sha256', (string) $user->id), 'endpoint_hash' => hash('sha256', 'endpoint'.$user->id), 'endpoint' => 'https://fcm.googleapis.com/example'.$user->id, 'public_key' => 'key', 'auth_token' => 'auth']);
        }

        return [$coach, $trainee];
    }

    private function relationship(User $coach, User $trainee): CoachTraineeRelationship
    {
        $relationship = CoachTraineeRelationship::create(['coach_user_id' => $coach->id, 'trainee_user_id' => $trainee->id, 'status' => 'active', 'requested_at' => now(), 'approved_at' => now()]);
        $relationship->subscriptions()->create(['package_name' => 'Coaching', 'starts_on' => today(), 'ends_on' => today()->addYear(), 'status' => 'active']);

        return $relationship;
    }

    private function program(User $coach): TrainingPlan
    {
        $plan = $coach->trainingPlans()->create(['name' => 'Program', 'structure_type' => 'program', 'duration_weeks' => 4]);
        $plan->forceFill(['workspace_type' => 'coach_template'])->save();
        $block = $plan->blocks()->create(['name' => 'Foundation', 'start_week' => 1, 'end_week' => 4, 'position' => 0, 'type' => 'normal']);
        $day = $plan->trainingDays()->create(['training_block_id' => $block->id, 'title' => 'Training', 'type' => 'training', 'weekdays' => [1, 3, 5], 'default_time' => '18:00']);
        $day->exercises()->create(['name' => 'Push-ups', 'sets' => 2, 'reps' => '10', 'position' => 0]);

        return $plan;
    }

    public function test_request_and_acceptance_notify_correct_recipient_once(): void
    {
        [$coach, $trainee] = $this->setupCoaching();
        $this->actingAs($coach)->post('/coach/requests', ['training_code' => $trainee->training_code])->assertRedirect();
        $this->post('/coach/requests', ['training_code' => $trainee->training_code])->assertRedirect();
        $row = NotificationDelivery::firstOrFail();
        $this->assertSame('coach_request', $row->kind);
        $this->assertSame($trainee->id, $row->user_id);
        $relationship = CoachTraineeRelationship::firstOrFail();
        $this->actingAs($trainee)->post('/my-coaching/relationships/'.$relationship->id, ['action' => 'accept'])->assertRedirect();
        $this->assertSame(2, NotificationDelivery::count());
        $accepted = NotificationDelivery::where('kind', 'coach_accepted')->firstOrFail();
        $this->assertSame($coach->id, $accepted->user_id);
        $this->assertFalse(app(PushDeliveries::class)->valid($row));
        $this->assertTrue(app(PushDeliveries::class)->valid($accepted));
    }

    public function test_assignment_bulk_schedule_and_controls_each_queue_one_event(): void
    {
        [$coach, $trainee] = $this->setupCoaching();
        $this->relationship($coach, $trainee);
        $copy = app(TrainingPlanDuplicator::class)->assign($coach, $trainee, $this->program($coach), []);
        $this->assertSame(1, NotificationDelivery::where('kind', 'coach_assigned')->count());
        $schedule = app(ProgramSchedule::class);
        $data = ['start_date' => today()->addDay()->toDateString(), 'nonce' => (string) Str::uuid()];
        $data['signature'] = $schedule->preview($copy, $data['start_date'], $data['nonce'], $coach)['signature'];
        $run = DB::transaction(function () use ($schedule, $copy, $data, $coach) {
            $paths = [];

            return $schedule->start($copy, $data, $coach, $paths);
        });
        $this->assertGreaterThan(5, $run->sessions()->count());
        $this->assertSame(1, NotificationDelivery::where('kind', 'coach_schedule')->count());
        foreach (['pause', 'resume', 'shift', 'end'] as $action) {
            $data = ['action' => $action, 'pause_date' => today()->toDateString(), 'start_date' => today()->addDays($action === 'shift' ? 20 : 5)->toDateString()];
            DB::transaction(function () use ($run, $data, $coach) {
                $control = app(ProgramControl::class);
                $fresh = $run->fresh();
                $preview = $control->preview($fresh, $data, $coach);
                $control->apply($fresh, $data, $preview['signature'], $coach);
            });
            $this->assertSame(1, NotificationDelivery::where('kind', 'coach_'.$action)->count());
        }
    }

    public function test_after_commit_only_and_rollback_has_no_notification(): void
    {
        [$coach, $trainee] = $this->setupCoaching();
        $relationship = $this->relationship($coach, $trainee);
        DB::beginTransaction();
        app(CoachNotifications::class)->connection($relationship, true);
        $this->assertDatabaseCount('notification_deliveries', 0);
        DB::rollBack();
        $this->assertDatabaseCount('notification_deliveries', 0);
        DB::transaction(fn () => app(CoachNotifications::class)->connection($relationship, true));
        $this->assertDatabaseCount('notification_deliveries', 1);
    }

    public function test_delivery_storage_failure_does_not_rollback_connection(): void
    {
        [$coach, $trainee] = $this->setupCoaching();
        $this->mock(PushDeliveries::class, fn ($mock) => $mock->shouldReceive('enqueue')->andThrow(new \RuntimeException('private-provider-message')));
        $this->actingAs($coach)->post('/coach/requests', ['training_code' => $trainee->training_code])->assertRedirect();
        $this->assertDatabaseCount('coach_trainee_relationships', 1);
    }

    public function test_optout_and_rejected_actions_queue_nothing(): void
    {
        [$coach, $trainee] = $this->setupCoaching();
        NotificationPreference::whereKey($trainee->id)->update(['coach_updates' => false]);
        $this->actingAs($coach)->post('/coach/requests', ['training_code' => $trainee->training_code])->assertRedirect();
        $this->assertDatabaseCount('notification_deliveries', 0);
        $template = $this->program($coach);
        $this->post('/coach/trainees/'.$trainee->id.'/assign', ['training_plan_id' => $template->id, 'confirmed' => true])->assertNotFound();
        $this->assertDatabaseCount('notification_deliveries', 0);
    }

    public function test_disconnected_relationship_cancels_queued_coach_delivery(): void
    {
        [$coach, $trainee] = $this->setupCoaching();
        $relationship = $this->relationship($coach, $trainee);
        app(CoachNotifications::class)->connection($relationship, true);
        $delivery = NotificationDelivery::firstOrFail();
        $this->assertTrue(app(PushDeliveries::class)->valid($delivery));
        $relationship->update(['status' => 'ended']);
        $this->assertFalse(app(PushDeliveries::class)->valid($delivery));
    }
}
