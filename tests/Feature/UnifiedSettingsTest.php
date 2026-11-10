<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UnifiedSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_personal_settings_save_profile_weight_goals_and_notifications_for_current_user_only(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $data = ['name' => 'Updated name', 'height_cm' => 175, 'current_weight_kg' => 80, 'training_goals' => ['get_stronger'], 'body_weight_goal' => 'lose', 'target_body_weight_kg' => 75, 'notifications' => ['workout_reminders' => true, 'coach_updates' => false, 'reminder_minutes' => 30], 'user_id' => $other->id, 'account_type' => 'coach'];
        $this->actingAs($user)->put('/profile', $data)->assertSessionHasNoErrors()->assertRedirect('/profile');
        $this->assertSame('Updated name', $user->fresh()->name);
        $this->assertSame('trainee', $user->fresh()->account_type);
        $this->assertSame($other->name, $other->fresh()->name);
        $this->assertDatabaseHas('notification_preferences', ['user_id' => $user->id, 'reminder_minutes' => 30]);
        $this->assertDatabaseMissing('notification_preferences', ['user_id' => $other->id]);
        $this->assertDatabaseHas('body_weight_entries', ['user_id' => $user->id, 'weight_kg' => 80]);
        $this->assertSame(['get_stronger'], $user->fresh()->profile->training_goals);
        $this->put('/profile', $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('body_weight_entries', 1);
        $this->put('/profile', [...$data, 'name' => 'Must not save', 'notifications' => ['workout_reminders' => true, 'coach_updates' => false, 'reminder_minutes' => 99]])->assertSessionHasErrors('notifications.reminder_minutes');
        $this->assertSame('Updated name', $user->fresh()->name);
        $this->assertDatabaseHas('notification_preferences', ['user_id' => $user->id, 'reminder_minutes' => 30]);
    }

    public function test_coach_account_details_share_validation_and_save_without_leaving_workspace(): void
    {
        $coach = User::factory()->create(['account_type' => 'coach']);
        $coach->coachProfile()->create(['display_name' => 'Coach']);
        $this->actingAs($coach)->post('/coach/settings', ['display_name' => 'Coaching', 'account' => ['name' => 'Account name', 'current_weight_kg' => 82], 'training_preferences' => ['training_goals' => ['get_stronger'], 'body_weight_goal' => 'maintain'], 'notifications' => ['workout_reminders' => false, 'coach_updates' => true, 'reminder_minutes' => 60]])->assertSessionHasNoErrors()->assertRedirect('/coach/settings');
        $this->assertSame('Account name', $coach->fresh()->name);
        $this->assertSame('Coaching', $coach->fresh()->coachProfile->display_name);
        $this->assertDatabaseHas('body_weight_entries', ['user_id' => $coach->id, 'weight_kg' => 82]);
        $this->post('/coach/settings', ['display_name' => 'Must not save', 'account' => ['current_weight_kg' => 1]])->assertSessionHasErrors('account.current_weight_kg');
        $this->assertSame('Coaching', $coach->fresh()->coachProfile->display_name);
        $this->post('/coach/settings', ['display_name' => 'Must not save', 'account' => ['user_id' => 999]])->assertSessionHasErrors('account');
        foreach (['/coach/settings', '/coach/settings/account', '/coach/settings/notifications', '/profile/coach'] as $route) {
            $this->get($route)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertInertia(fn (Assert $p) => $p->where('trainingContext.workspace', 'coach')->where('coachProfile.display_name', 'Coaching')->where('preferences.reminder_minutes', 60));
        }
    }

    public function test_personal_settings_aliases_share_current_account_data_and_never_enable_coach_access(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        foreach (['/profile', '/profile/edit', '/training-preferences', '/notifications', '/profile/notifications'] as $route) {
            $this->get($route)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertInertia(fn (Assert $p) => $p->where('coachProfile', null)->has('preferences')->has('options')->where('deviceCount', 0));
        }
        $this->get('/coach/settings')->assertForbidden();
        $this->post('/coach/settings', ['display_name' => 'No promotion'])->assertForbidden();
        $this->assertDatabaseCount('coach_profiles', 0);
    }
}
