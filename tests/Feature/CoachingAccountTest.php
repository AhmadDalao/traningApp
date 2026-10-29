<?php

namespace Tests\Feature;

use App\Models\CoachPairingToken;
use App\Models\CoachSubscriptionPlan;
use App\Models\CoachTraineeRelationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoachingAccountTest extends TestCase
{
    use RefreshDatabase;

    private function coach(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['account_type' => User::COACH])->save();
        $user->coachProfile()->create(['display_name' => 'Coach '.$user->id]);

        return $user;
    }

    private function relationship(User $coach, User $trainee, string $status = 'active'): CoachTraineeRelationship
    {
        return CoachTraineeRelationship::create(['coach_user_id' => $coach->id, 'trainee_user_id' => $trainee->id, 'requested_by' => $coach->id, 'status' => $status, 'requested_at' => now(), 'approved_at' => $status === 'active' ? now() : null]);
    }

    public function test_every_account_gets_a_unique_opaque_training_code(): void
    {
        $users = User::factory()->count(30)->create();
        $this->assertCount(30, $users->pluck('training_code')->unique());
        foreach ($users as $user) {
            $this->assertMatchesRegularExpression('/^TP-[2-9A-HJ-NP-Z]{8}$/', $user->training_code);
        }
    }

    public function test_coach_registration_and_personal_registration_share_authentication(): void
    {
        $this->post('/register', ['name' => 'Coach A', 'email' => 'coach@example.test', 'password' => 'training-password', 'password_confirmation' => 'training-password', 'account_type' => 'coach', 'display_name' => 'Coach A'])->assertRedirect('/coach');
        $user = User::where('email', 'coach@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->isCoach());
        $this->get('/dashboard')->assertOk();
        $this->post('/logout');
        $this->post('/register', ['name' => 'Trainee', 'email' => 'trainee@example.test', 'password' => 'training-password', 'password_confirmation' => 'training-password'])->assertRedirect('/dashboard');
        $this->assertFalse(User::where('email', 'trainee@example.test')->first()->isCoach());
    }

    public function test_registration_cannot_assign_an_arbitrary_role_or_omit_coach_name(): void
    {
        $base = ['name' => 'A', 'email' => 'a@example.test', 'password' => 'training-password', 'password_confirmation' => 'training-password'];
        $this->post('/register', [...$base, 'account_type' => 'super_admin'])->assertSessionHasErrors('account_type');
        $this->post('/register', [...$base, 'account_type' => 'coach'])->assertSessionHasErrors('display_name');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_trainee_cannot_enable_coaching_even_with_forged_role_fields(): void
    {
        $user = User::factory()->create();
        $plan = $user->trainingPlans()->create(['name' => 'Personal']);
        $before = $user->fresh()->getAttributes();
        $this->actingAs($user)->withSession(['workspace' => 'personal']);
        $this->get('/profile/coach')->assertForbidden();
        $this->post('/profile/coach', ['display_name' => 'Coaching'])->assertForbidden();
        $this->postJson('/profile/coach', ['display_name' => 'Coaching', 'account_type' => 'coach', 'is_coach' => true, 'active' => true])->assertForbidden();
        $this->post('/profile/coach', [])->assertForbidden();
        $this->post('/workspace', ['workspace' => 'coach'])->assertForbidden()->assertSessionHas('workspace', 'personal');
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('coach_profiles', 0);
        $this->assertEquals($user->id, $plan->fresh()->user_id);
        $this->get('/dashboard')->assertOk();
        $this->get('/profile')->assertOk();
        $this->get('/my-coaching')->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_existing_coach_can_edit_profile_and_keeps_personal_data_and_identity(): void
    {
        $user = $this->coach();
        $plan = $user->trainingPlans()->create(['name' => 'Personal']);
        $before = $user->only('id', 'email', 'password', 'training_code');
        $profileId = $user->coachProfile->id;
        $this->actingAs($user)->get('/profile/coach')->assertOk();
        $this->post('/profile/coach', ['display_name' => 'Coaching', 'specialization' => 'Strength', 'bio' => 'Training together.', 'account_type' => 'trainee', 'active' => false])->assertRedirect('/coach')->assertSessionHas('success', 'Coach profile updated.');
        $this->assertDatabaseHas('coach_profiles', ['id' => $profileId, 'display_name' => 'Coaching', 'specialization' => 'Strength', 'bio' => 'Training together.', 'active' => true]);
        $this->post('/profile/coach', ['display_name' => 'Updated'])->assertRedirect('/coach');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('coach_profiles', 1);
        $this->assertDatabaseHas('coach_profiles', ['id' => $profileId, 'display_name' => 'Updated']);
        $this->assertTrue($user->fresh()->isCoach());
        $this->assertEquals($before, $user->fresh()->only(array_keys($before)));
        $this->assertEquals($user->id, $plan->fresh()->user_id);
        $user->refresh();
        $this->post('/workspace', ['workspace' => 'personal'])->assertRedirect('/dashboard');
        $this->post('/workspace', ['workspace' => 'coach'])->assertRedirect('/coach');
        $this->assertAuthenticatedAs($user);
    }

    public function test_inactive_coach_cannot_reactivate_through_profile_editing(): void
    {
        $user = $this->coach();
        $user->coachProfile()->update(['active' => false]);
        $before = $user->coachProfile->getAttributes();
        $this->actingAs($user)->get('/profile/coach')->assertForbidden();
        $this->post('/profile/coach', ['display_name' => 'Reactivated', 'active' => true])->assertForbidden();
        $this->assertSame($before, $user->coachProfile->fresh()->getAttributes());
        $this->post('/workspace', ['workspace' => 'coach'])->assertForbidden();
    }

    public function test_coach_role_without_profile_cannot_create_one_through_profile_editing(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['account_type' => User::COACH])->save();
        $this->actingAs($user)->get('/profile/coach')->assertForbidden();
        $this->post('/profile/coach', ['display_name' => 'New profile'])->assertForbidden();
        $this->assertDatabaseCount('coach_profiles', 0);
    }

    public function test_coach_profile_without_coach_role_cannot_enable_workspace(): void
    {
        $user = User::factory()->create();
        $user->coachProfile()->create(['display_name' => 'Existing profile']);
        $this->actingAs($user)->get('/profile/coach')->assertForbidden();
        $this->post('/profile/coach', ['display_name' => 'Upgrade'])->assertForbidden();
        $this->assertSame(User::TRAINEE, $user->fresh()->account_type);
        $this->assertDatabaseHas('coach_profiles', ['user_id' => $user->id, 'display_name' => 'Existing profile']);
    }

    public function test_guest_cannot_open_or_submit_coach_profile(): void
    {
        $this->get('/profile/coach')->assertRedirect('/login');
        $this->post('/profile/coach', ['display_name' => 'Guest'])->assertRedirect('/login');
        $this->assertDatabaseCount('coach_profiles', 0);
    }

    public function test_normal_accounts_cannot_open_coach_routes_or_switch_workspace(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['/coach', '/coach/trainees', '/coach/subscriptions'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/workspace', ['workspace' => 'coach'])->assertForbidden();
        $this->post('/coach/lookup', ['training_code' => 'TP-ABCDEFGH'])->assertForbidden();
    }

    public function test_lookup_is_exact_and_returns_no_sensitive_profile(): void
    {
        $coach = $this->coach();
        $user = User::factory()->create(['name' => 'Alex Johnson']);
        $this->actingAs($coach)->postJson('/coach/lookup', ['training_code' => strtolower($user->training_code)])->assertOk()->assertExactJson(['member' => ['name' => 'Alex J.', 'training_code' => $user->training_code]]);
        $this->postJson('/coach/lookup', ['training_code' => $user->email])->assertUnprocessable();
        $this->postJson('/coach/lookup', ['training_code' => substr($user->training_code, 0, 8)])->assertUnprocessable();
        $this->postJson('/coach/lookup', ['training_code' => $coach->training_code])->assertExactJson(['member' => null]);
    }

    public function test_request_needs_acceptance_and_only_addressed_trainee_can_accept(): void
    {
        $coach = $this->coach();
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $this->actingAs($coach)->post('/coach/requests', ['training_code' => $user->training_code])->assertRedirect('/coach/trainees');
        $row = CoachTraineeRelationship::firstOrFail();
        $this->post('/coach/requests', ['training_code' => $user->training_code])->assertRedirect();
        $this->assertDatabaseCount('coach_trainee_relationships', 1);
        $this->get('/coach/trainees/'.$user->id)->assertNotFound();
        $this->post('/my-coaching/relationships/'.$row->id, ['action' => 'accept'])->assertForbidden();
        $this->actingAs($stranger)->post('/my-coaching/relationships/'.$row->id, ['action' => 'accept'])->assertForbidden();
        $this->actingAs($user)->post('/my-coaching/relationships/'.$row->id, ['action' => 'accept'])->assertRedirect();
        $this->actingAs($coach)->get('/coach/trainees/'.$user->id)->assertOk();
        $this->assertAuthenticatedAs($coach);
    }

    public function test_coaches_can_be_coached_by_multiple_coaches_but_not_themselves(): void
    {
        $a = $this->coach();
        $b = $this->coach();
        $c = $this->coach();
        $this->actingAs($a)->post('/coach/requests', ['training_code' => $a->training_code])->assertSessionHasErrors('training_code');
        $this->post('/coach/requests', ['training_code' => $b->training_code])->assertRedirect();
        $this->actingAs($c)->post('/coach/requests', ['training_code' => $b->training_code])->assertRedirect();
        foreach (CoachTraineeRelationship::all() as $row) {
            $this->actingAs($b)->post('/my-coaching/relationships/'.$row->id, ['action' => 'accept'])->assertRedirect();
        }
        $this->assertEquals(2, CoachTraineeRelationship::where('status', 'active')->count());
    }

    public function test_decline_and_disconnect_preserve_training_and_reconnect_requires_fresh_consent(): void
    {
        $coach = $this->coach();
        $user = User::factory()->create();
        $plan = $user->trainingPlans()->create(['name' => 'Keep me']);
        $row = $this->relationship($coach, $user, 'pending');
        $this->actingAs($user)->post('/my-coaching/relationships/'.$row->id, ['action' => 'decline'])->assertRedirect();
        $this->assertSame('ended', $row->fresh()->status);
        $this->actingAs($coach)->post('/coach/requests', ['training_code' => $user->training_code])->assertRedirect();
        $new = CoachTraineeRelationship::latest('id')->first();
        $this->assertNotEquals($row->id, $new->id);
        $this->actingAs($user)->post('/my-coaching/relationships/'.$new->id, ['action' => 'accept']);
        $sub = $new->subscriptions()->create(['package_name' => 'Service', 'starts_on' => today(), 'status' => 'active']);
        $this->post('/my-coaching/relationships/'.$new->id, ['action' => 'end'])->assertSessionHasErrors('confirmed');
        $this->post('/my-coaching/relationships/'.$new->id, ['action' => 'end', 'confirmed' => true])->assertRedirect();
        $this->assertSame('ended', $sub->fresh()->status);
        $this->actingAs($coach)->get('/coach/trainees/'.$user->id)->assertNotFound();
        $this->assertNotNull($plan->fresh());
    }

    public function test_qr_is_hashed_expires_single_use_and_does_not_authenticate_target(): void
    {
        $user = User::factory()->create();
        $coach = $this->coach();
        $response = $this->actingAs($user)->postJson('/my-coaching/qr')->assertOk();
        $raw = basename($response['url']);
        $this->assertDatabaseHas('coach_pairing_tokens', ['token_hash' => hash('sha256', $raw)]);
        $this->assertDatabaseMissing('coach_pairing_tokens', ['token_hash' => $raw]);
        $this->actingAs($coach)->get('/coach/connect/'.$raw)->assertOk();
        $this->assertNull(CoachPairingToken::first()->used_at);
        $this->assertAuthenticatedAs($coach);
        $this->post('/coach/requests', ['token' => $raw])->assertRedirect();
        $this->assertSame('pending', CoachTraineeRelationship::first()->status);
        $this->get('/coach/connect/'.$raw)->assertNotFound();
        $this->post('/coach/requests', ['token' => $raw])->assertNotFound();
        $this->assertDatabaseCount('coach_trainee_relationships', 1);
        $second = basename($this->actingAs($user)->postJson('/my-coaching/qr')['url']);
        $this->travel(11)->minutes();
        $this->actingAs($coach)->get('/coach/connect/'.$second)->assertNotFound();
    }

    public function test_qr_regeneration_invalidates_old_link_and_login_returns_to_invitation(): void
    {
        $user = User::factory()->create();
        $coach = $this->coach();
        $old = basename($this->actingAs($user)->postJson('/my-coaching/qr')['url']);
        $new = basename($this->postJson('/my-coaching/qr')['url']);
        $this->actingAs($coach)->get('/coach/connect/'.$old)->assertNotFound();
        $this->post('/logout');
        $this->get('/coach/connect/'.$new)->assertRedirect('/login');
        $this->post('/login', ['email' => $coach->email, 'password' => 'password'])->assertRedirect('/coach/connect/'.$new);
        $this->assertAuthenticatedAs($coach);
    }

    public function test_package_snapshot_dates_pause_resume_end_and_one_open_subscription(): void
    {
        $coach = $this->coach();
        $user = User::factory()->create();
        $row = $this->relationship($coach, $user);
        $this->actingAs($coach)->post('/coach/subscription-plans', ['name' => 'Eight weeks', 'duration_days' => 56, 'active' => true])->assertRedirect();
        $package = CoachSubscriptionPlan::firstOrFail();
        $data = ['relationship_id' => $row->id, 'package_id' => $package->id, 'starts_on' => today()->toDateString()];
        $this->post('/coach/subscriptions', $data)->assertRedirect()->assertSessionHasNoErrors();
        $sub = $row->subscriptions()->firstOrFail();
        $this->assertSame(today()->addDays(55)->toDateString(), $sub->ends_on->toDateString());
        $this->post('/coach/subscriptions', $data)->assertSessionHasErrors('subscription');
        $this->patch('/coach/subscriptions/'.$sub->id, ['action' => 'pause'])->assertRedirect();
        $this->assertFalse($sub->fresh()->isEffective());
        $this->post('/coach/subscriptions', $data)->assertSessionHasErrors('subscription');
        $this->patch('/coach/subscriptions/'.$sub->id, ['action' => 'resume'])->assertRedirect();
        $this->assertTrue($sub->fresh()->isEffective());
        $this->put('/coach/subscription-plans/'.$package->id, ['name' => 'Changed', 'duration_days' => 20, 'active' => false]);
        $this->assertSame('Eight weeks', $sub->fresh()->package_name);
        $this->patch('/coach/subscriptions/'.$sub->id, ['action' => 'end', 'confirmed' => true])->assertRedirect();
        $this->assertSame('ended', $sub->fresh()->status);
    }

    public function test_subscription_date_boundaries_and_foreign_package_permissions(): void
    {
        $a = $this->coach();
        $b = $this->coach();
        $user = User::factory()->create();
        $row = $this->relationship($a, $user);
        $package = CoachSubscriptionPlan::create(['coach_user_id' => $b->id, 'name' => 'Private', 'active' => true]);
        $this->actingAs($a)->put('/coach/subscription-plans/'.$package->id, ['name' => 'No', 'active' => true])->assertForbidden();
        $this->post('/coach/subscriptions', ['relationship_id' => $row->id, 'package_id' => $package->id, 'starts_on' => today()->toDateString()])->assertNotFound();
        $sub = $row->subscriptions()->create(['package_name' => 'Future', 'starts_on' => today()->addDay(), 'ends_on' => today()->addDays(3), 'status' => 'active']);
        $this->assertFalse($sub->isEffective());
        $this->travel(1)->days();
        $this->assertTrue($sub->isEffective());
        $this->travel(2)->days();
        $this->assertTrue($sub->isEffective());
        $this->travel(1)->days();
        $this->assertFalse($sub->isEffective());
        $this->assertSame('expired', $sub->effective_status);
    }

    public function test_lookup_and_requests_are_rate_limited(): void
    {
        $coach = $this->coach();
        $user = User::factory()->create();
        $this->actingAs($coach);
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/coach/lookup', ['training_code' => $user->training_code])->assertOk();
        }
        $this->postJson('/coach/lookup', ['training_code' => $user->training_code])->assertStatus(429);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/coach/requests', ['training_code' => $user->training_code])->assertRedirect();
        }
        $this->post('/coach/requests', ['training_code' => $user->training_code])->assertStatus(429);
    }

    public function test_registration_retries_a_database_training_code_collision(): void
    {
        $existing = User::factory()->create();
        $dispatcher = User::getEventDispatcher();
        User::setEventDispatcher(clone $dispatcher);
        $attempts = 0;
        User::creating(function ($user) use ($existing, &$attempts) {
            if ($user->email === 'collision@example.test' && $attempts++ === 0) {
                $user->training_code = $existing->training_code;
            }
        });
        try {
            $this->post('/register', ['name' => 'Collision', 'email' => 'collision@example.test', 'password' => 'Password-Testing-123', 'password_confirmation' => 'Password-Testing-123'])->assertRedirect('/dashboard')->assertSessionHasNoErrors();
            $this->assertSame(2, $attempts);
            $this->assertNotSame($existing->training_code, User::where('email', 'collision@example.test')->firstOrFail()->training_code);
        } finally {
            User::setEventDispatcher($dispatcher);
        }
    }
}
