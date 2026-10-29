<?php

namespace Tests\Feature;

use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\ProgramRun;
use App\Models\User;
use App\Models\WebPushSubscription;
use App\Services\Notifications\PushDeliveries;
use App\Services\Notifications\PushDevices;
use App\Services\Notifications\PushTransport;
use App\Services\Notifications\WorkoutReminders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Minishlink\WebPush\VAPID;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PushNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->withCredentials();
        $this->travelTo(now()->setDate(2026, 11, 1)->setTime(12, 0));
        $this->mock(PushTransport::class, fn ($mock) => $mock->shouldReceive('configured')->andReturn(true));
    }

    private function device(User $user, string $token = 'a'): WebPushSubscription
    {
        return WebPushSubscription::create(['user_id' => $user->id, 'device_hash' => hash('sha256', str_repeat($token, 64)), 'endpoint_hash' => hash('sha256', $token),
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/'.$token, 'public_key' => 'private-p256dh', 'auth_token' => 'private-auth']);
    }

    private function opted(): array
    {
        $user = User::factory()->create();
        NotificationPreference::create(['user_id' => $user->id, 'workout_reminders' => true, 'coach_updates' => true]);

        return [$user, $this->device($user)];
    }

    private function entry(User $user, array $fields = [])
    {
        return $user->scheduleEntries()->create([...['title' => 'Secret workout title', 'entry_type' => 'training', 'scheduled_date' => today(), 'scheduled_time' => '13:00', 'status' => 'planned'], ...$fields]);
    }

    private function payload(string $suffix = 'one'): array
    {
        return ['endpoint' => 'https://fcm.googleapis.com/fcm/send/'.$suffix, 'keys' => ['p256dh' => rtrim(strtr(base64_encode("\x04".str_repeat('x', 64)), '+/', '-_'), '='), 'auth' => rtrim(strtr(base64_encode(str_repeat('a', 16)), '+/', '-_'), '=')]];
    }

    public function test_settings_are_off_by_default_and_do_not_expose_secrets(): void
    {
        $user = User::factory()->create();
        $device = $this->device($user);
        $this->actingAs($user)->withCookie(PushDevices::COOKIE, str_repeat('a', 64))->get('/notifications')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertInertia(fn (Assert $p) => $p->component('Notifications/Index')->where('preferences.workout_reminders', false)->where('preferences.coach_updates', false)->where('preferences.reminder_minutes', 60)->where('deviceEnabled', true));
        $raw = DB::table('web_push_subscriptions')->first();
        $this->assertNotSame($device->endpoint, $raw->endpoint);
        $this->assertNotSame('private-auth', $raw->auth_token);
        $this->assertArrayNotHasKey('endpoint', $device->toArray());
        $this->assertArrayNotHasKey('auth_token', $device->toArray());
    }

    public function test_subscribe_is_idempotent_and_separate_from_preferences(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withCookie(PushDevices::COOKIE, str_repeat('a', 64));
        $this->postJson('/notifications/subscription', $this->payload())->assertOk();
        $this->postJson('/notifications/subscription', $this->payload())->assertOk();
        $this->assertDatabaseCount('web_push_subscriptions', 1);
        $this->assertDatabaseCount('notification_preferences', 0);
    }

    public function test_foreign_endpoint_cannot_be_claimed_and_injected_owner_ids_are_ignored(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->actingAs($a)->withCookie(PushDevices::COOKIE, str_repeat('a', 64))->postJson('/notifications/subscription', $this->payload())->assertOk();
        $this->actingAs($b)->withCookie(PushDevices::COOKIE, str_repeat('b', 64))->postJson('/notifications/subscription', $this->payload())->assertStatus(409);
        $this->deleteJson('/notifications/subscription', ['user_id' => $a->id])->assertOk();
        $this->putJson('/notifications/preferences', ['user_id' => $a->id, 'workout_reminders' => true, 'coach_updates' => false, 'reminder_minutes' => 30])->assertOk();
        $this->assertDatabaseCount('web_push_subscriptions', 1);
        $this->assertNull(NotificationPreference::find($a->id));
        $this->assertSame(30, NotificationPreference::find($b->id)->reminder_minutes);
        $this->postJson('/notifications/test', ['user_id' => $a->id])->assertStatus(422);
    }

    public static function badEndpoints(): array
    {
        return array_map(fn ($url) => [$url], ['http://fcm.googleapis.com/x', 'https://localhost/x', 'https://127.0.0.1/x', 'https://fcm.googleapis.com.evil.test/x', 'https://evil.test@fcm.googleapis.com/x', 'https://fcm.googleapis.com:8080/x', 'https://fcm.googleapis.com/x#fragment']);
    }

    #[DataProvider('badEndpoints')]
    public function test_arbitrary_endpoint_urls_are_rejected(string $url): void
    {
        $this->actingAs(User::factory()->create())->withCookie(PushDevices::COOKIE, str_repeat('a', 64))->postJson('/notifications/subscription', [...$this->payload(), 'endpoint' => $url])->assertStatus(422);
        $this->assertDatabaseCount('web_push_subscriptions', 0);
    }

    public function test_invalid_keys_and_missing_device_cookie_are_rejected(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/notifications/subscription', $this->payload())->assertStatus(409);
        $this->withCookie(PushDevices::COOKIE, str_repeat('a', 64))->postJson('/notifications/subscription', [...$this->payload(), 'keys' => ['p256dh' => 'bad', 'auth' => 'bad']])->assertStatus(422);
    }

    public function test_authentication_is_required_for_all_push_routes(): void
    {
        $this->get('/notifications')->assertRedirect('/login');
        foreach (['/notifications/subscription', '/notifications/test'] as $url) {
            $this->postJson($url)->assertUnauthorized();
        }
        $this->putJson('/notifications/preferences')->assertUnauthorized();
        $this->deleteJson('/notifications/subscription')->assertUnauthorized();
    }

    public function test_logout_revokes_only_current_device_and_preserves_preferences(): void
    {
        [$user] = $this->opted();
        $this->device($user, 'b');
        $this->actingAs($user)->withCookie(PushDevices::COOKIE, str_repeat('a', 64))->post('/logout')->assertRedirect('/login');
        $this->assertDatabaseCount('web_push_subscriptions', 1);
        $this->assertTrue(NotificationPreference::find($user->id)->workout_reminders);
    }

    public function test_new_login_revokes_prior_account_association_on_device(): void
    {
        [$old] = $this->opted();
        $new = User::factory()->create(['password' => 'test-password']);
        $this->withCookie(PushDevices::COOKIE, str_repeat('a', 64))->post('/login', ['email' => $new->email, 'password' => 'test-password'])->assertRedirect();
        $this->assertDatabaseCount('web_push_subscriptions', 0);
        $this->assertNull(NotificationPreference::find($new->id));
    }

    public function test_preferences_validate_allowed_lead_times(): void
    {
        $this->actingAs(User::factory()->create())->putJson('/notifications/preferences', ['workout_reminders' => true, 'coach_updates' => false, 'reminder_minutes' => 90])->assertUnprocessable();
        $this->assertDatabaseCount('notification_preferences', 0);
    }

    public function test_each_device_receives_once_across_repeated_command_runs(): void
    {
        [$user] = $this->opted();
        $this->device($user, 'b');
        $this->entry($user);
        $this->mock(PushTransport::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('send')->twice()->andReturn('sent');
        });
        $this->artisan('notifications:send')->assertSuccessful();
        $this->artisan('notifications:send')->assertSuccessful();
        $this->assertSame(2, NotificationDelivery::where('status', 'sent')->count());
        $payload = json_encode(NotificationDelivery::first()->payload);
        $this->assertStringNotContainsString('Secret', $payload);
        foreach (['weight', 'calories', 'email', 'BMI', 'endpoint', 'auth_token'] as $word) {
            $this->assertStringNotContainsString($word, $payload);
        }
    }

    public static function exclusions(): array
    {
        return [[['status' => 'completed']], [['status' => 'skipped']], [['started_at' => '2026-11-01 11:30:00']], [['entry_type' => 'rest']], [['scheduled_time' => null]], [['scheduled_time' => '11:59']], [['scheduled_time' => '12:50']], [['scheduled_date' => '2026-11-02']]];
    }

    #[DataProvider('exclusions')]
    public function test_ineligible_workouts_are_never_enqueued(array $fields): void
    {
        [$user] = $this->opted();
        $this->entry($user, $fields);
        $this->assertSame(0, app(WorkoutReminders::class)->enqueue(50));
    }

    public function test_disabled_preference_and_unsubscribed_users_are_excluded(): void
    {
        [$user, $device] = $this->opted();
        $this->entry($user);
        NotificationPreference::whereKey($user->id)->update(['workout_reminders' => false]);
        $this->assertSame(0, app(WorkoutReminders::class)->enqueue(50));
        NotificationPreference::whereKey($user->id)->update(['workout_reminders' => true]);
        $device->delete();
        $this->assertSame(0, app(WorkoutReminders::class)->enqueue(50));
    }

    public function test_simple_plan_and_manual_workouts_share_reminder_engine(): void
    {
        [$user] = $this->opted();
        $plan = $user->trainingPlans()->create(['name' => 'Simple']);
        $day = $plan->trainingDays()->create(['title' => 'Workout', 'type' => 'training']);
        $this->entry($user, ['training_day_id' => $day->id]);
        $this->entry($user);
        $this->assertSame(2, app(WorkoutReminders::class)->enqueue(50));
    }

    public static function programStates(): array
    {
        return [['active', 1], ['paused', 0], ['ended', 0]];
    }

    #[DataProvider('programStates')]
    public function test_program_availability_is_authoritative(string $status, int $expected): void
    {
        [$user] = $this->opted();
        $run = ProgramRun::create(['user_id' => $user->id, 'start_token' => hash('sha256', 'test'), 'started_on' => today(), 'status' => $status, 'paused_from' => today(), 'definition_snapshot' => []]);
        $this->entry($user, ['program_run_id' => $run->id]);
        $this->assertSame($expected, app(WorkoutReminders::class)->enqueue(50));
    }

    public function test_completion_reschedule_and_optout_after_selection_cancel_delivery(): void
    {
        foreach (['complete', 'move', 'optout', 'delete-device'] as $change) {
            [$user, $device] = $this->optedUnique();
            $entry = $this->entry($user);
            app(WorkoutReminders::class)->enqueue(50);
            $row = NotificationDelivery::where('user_id', $user->id)->firstOrFail();
            match ($change) {
                'complete' => $entry->update(['status' => 'completed']),
                'move' => $entry->update(['scheduled_time' => '15:00']),
                'optout' => NotificationPreference::whereKey($user->id)->update(['workout_reminders' => false]),
                default => $device->delete(),
            };
            $this->assertContains(app(PushDeliveries::class)->deliver($row->id), ['cancelled', 'unclaimed']);
        }
    }

    private function optedUnique(): array
    {
        $user = User::factory()->create();
        NotificationPreference::create(['user_id' => $user->id, 'workout_reminders' => true]);

        return [$user, $this->device($user, (string) $user->id)];
    }

    public function test_rescheduled_workout_gets_new_reminder_at_current_time(): void
    {
        [$user] = $this->opted();
        $entry = $this->entry($user);
        app(WorkoutReminders::class)->enqueue(50);
        $old = NotificationDelivery::firstOrFail();
        $entry->update(['scheduled_time' => '15:00']);
        $this->assertSame('cancelled', app(PushDeliveries::class)->deliver($old->id));
        $this->travelTo(now()->setTime(14, 0));
        $this->assertSame(1, app(WorkoutReminders::class)->enqueue(50));
        $this->assertSame(2, NotificationDelivery::count());
    }

    public function test_midnight_window_uses_application_timezone_and_selected_lead(): void
    {
        [$user] = $this->opted();
        $this->travelTo(now()->setTime(23, 30));
        NotificationPreference::whereKey($user->id)->update(['reminder_minutes' => 120]);
        $this->entry($user, ['scheduled_date' => today()->addDay(), 'scheduled_time' => '01:30']);
        $this->assertSame(1, app(WorkoutReminders::class)->enqueue(50));
        $this->assertSame('Asia/Riyadh', config('app.timezone'));
    }

    public function test_retry_is_bounded_and_uses_stable_notification_tag(): void
    {
        [$user] = $this->opted();
        $this->entry($user);
        $tags = [];
        $this->mock(PushTransport::class, function ($mock) use (&$tags) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('send')->times(3)->andReturnUsing(function ($device, $payload) use (&$tags) {
                $tags[] = $payload['tag'];

                return 'temporary';
            });
        });
        app(WorkoutReminders::class)->enqueue(50);
        $row = NotificationDelivery::firstOrFail();
        foreach ([0, 1, 2] as $minutes) {
            $this->travel($minutes)->minutes();
            $this->assertSame('temporary', app(PushDeliveries::class)->deliver($row->id));
        }
        $this->assertSame('failed', $row->fresh()->status);
        $this->assertSame('unclaimed', app(PushDeliveries::class)->deliver($row->id));
        $this->assertCount(1, array_unique($tags));
    }

    public function test_expired_endpoint_is_removed_without_blocking_other_devices(): void
    {
        [$user] = $this->opted();
        $this->device($user, 'b');
        $this->entry($user);
        $this->mock(PushTransport::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('send')->andReturn('expired', 'sent');
        });
        $this->artisan('notifications:send')->assertSuccessful();
        $this->assertDatabaseCount('web_push_subscriptions', 1);
        $this->assertSame('sent', NotificationDelivery::firstOrFail()->status);
    }

    public function test_test_notification_is_current_device_only_and_throttled(): void
    {
        [$user] = $this->opted();
        $this->device($user, 'b');
        $this->actingAs($user)->withCookie(PushDevices::COOKIE, str_repeat('a', 64));
        $this->postJson('/notifications/test')->assertOk();
        $this->postJson('/notifications/test')->assertOk();
        $this->postJson('/notifications/test')->assertStatus(429);
        $this->assertDatabaseCount('notification_deliveries', 1);
    }

    public function test_deep_links_require_login_and_handle_deleted_or_foreign_resources(): void
    {
        [$user] = $this->opted();
        $entry = $this->entry($user);
        $this->get('/notifications/open/session/'.$entry->id)->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/notifications/open/session/'.$entry->id)->assertRedirect('/calendar');
        $this->actingAs($user)->get('/notifications/open/session/'.$entry->id)->assertRedirect('/schedule-entries/'.$entry->id);
        $entry->delete();
        $this->get('/notifications/open/session/'.$entry->id)->assertRedirect('/calendar');
    }

    public function test_user_deletion_cascades_all_notification_data(): void
    {
        [$user] = $this->opted();
        $this->entry($user);
        app(WorkoutReminders::class)->enqueue(50);
        $user->delete();
        foreach (['notification_deliveries', 'web_push_subscriptions', 'notification_preferences'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_manifest_worker_and_private_response_headers(): void
    {
        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);
        $this->assertSame('/', $manifest['scope']);
        $this->assertSame('/dashboard', $manifest['start_url']);
        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path($icon['src']));
        }
        $worker = $this->get('/sw.js')->assertOk()->assertHeader('Content-Type', 'application/javascript')->assertHeader('Service-Worker-Allowed', '/');
        $this->assertStringContainsString('STATIC_ASSETS', $worker->getContent());
        $this->get('/login')->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_disabled_transport_is_a_safe_command_noop(): void
    {
        $this->app->forgetInstance(PushTransport::class);
        config(['webpush.enabled' => false]);
        $this->artisan('notifications:send')->expectsOutput('Push disabled or unconfigured. Nothing sent.')->assertSuccessful();
    }

    public function test_coach_notification_settings_keep_workspace_without_exposing_trainee_data(): void
    {
        $coach = User::factory()->create(['account_type' => 'coach']);
        $coach->coachProfile()->create(['display_name' => 'Coach']);
        $this->actingAs($coach)->withSession(['workspace' => 'coach'])->get('/notifications')
            ->assertInertia(fn (Assert $page) => $page->where('trainingContext.workspace', 'coach')->where('trainingContext.trainee', null)->where('auth.user.id', $coach->id));
    }

    public function test_device_rotation_cancels_old_deliveries_without_changing_preferences(): void
    {
        [$user] = $this->opted();
        $this->entry($user);
        app(WorkoutReminders::class)->enqueue(50);
        $this->assertDatabaseCount('notification_deliveries', 1);
        $this->actingAs($user)->withCookie(PushDevices::COOKIE, str_repeat('a', 64))
            ->postJson('/notifications/subscription', $this->payload('rotated'))->assertOk();
        $this->assertDatabaseCount('notification_deliveries', 0);
        $this->assertDatabaseCount('web_push_subscriptions', 1);
        $this->assertTrue(NotificationPreference::find($user->id)->workout_reminders);
    }

    public function test_vapid_configuration_validation_uses_ephemeral_in_memory_test_keys(): void
    {
        $transport = new PushTransport;
        config(['webpush.enabled' => true, 'webpush.vapid' => ['subject' => null, 'publicKey' => null, 'privateKey' => null]]);
        $this->assertFalse($transport->configured());
        $keys = VAPID::createVapidKeys();
        config(['webpush.vapid' => [...$keys, 'subject' => 'mailto:qa@example.test']]);
        $this->assertTrue($transport->configured());
        config(['webpush.enabled' => false]);
        $this->assertFalse($transport->configured());
    }
}
