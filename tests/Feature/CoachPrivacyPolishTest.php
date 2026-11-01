<?php

namespace Tests\Feature;

use App\Models\CoachTraineeRelationship;
use App\Models\User;
use App\Services\CoachWorkspaceSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CoachPrivacyPolishTest extends TestCase
{
    use RefreshDatabase;

    private User $coach;

    private User $other;

    private User $trainee;

    private CoachTraineeRelationship $relationship;

    private array $plans;

    private array $entries;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');
        $this->travelTo(now()->setDate(2026, 11, 1)->setTime(12, 0));
        $this->coach = $this->coach();
        $this->other = $this->coach();
        $this->trainee = User::factory()->create();
        $this->relationship = $this->connect($this->coach, $this->trainee);
        $otherRelationship = $this->connect($this->other, $this->trainee);
        $this->plans = [];
        $this->entries = [];
        foreach (['private', 'other', 'own'] as $i => $kind) {
            $plan = $this->trainee->trainingPlans()->create(['name' => 'Secret '.$kind]);
            if ($kind !== 'private') {
                $plan->forceFill(['workspace_type' => 'coach_assigned'])->save();
                $r = $kind === 'own' ? $this->relationship : $otherRelationship;
                $r->assignments()->create(['coach_subscription_id' => $r->subscriptions()->first()->id, 'assigned_training_plan_id' => $plan->id, 'assigned_at' => now(), 'status' => 'active']);
            }
            $day = $plan->trainingDays()->create(['title' => 'Day '.$kind, 'type' => 'training', 'position' => 0]);
            $day->exercises()->create(['name' => 'Exercise '.$kind, 'sets' => 1, 'reps' => '10', 'position' => 0]);
            $entry = $this->trainee->scheduleEntries()->create(['training_day_id' => $day->id, 'title' => 'Workout '.$kind, 'entry_type' => 'training', 'status' => 'completed', 'scheduled_date' => today()->subDays(3 - $i), 'completed_at' => now(), 'scheduled_by_user_id' => $kind === 'own' ? $this->coach->id : $this->other->id]);
            $exercise = $entry->sessionExercises()->create(['name' => 'Same exercise', 'source_exercise_key' => 'builtin:privacy', 'tracking_mode' => 'reps', 'planned_sets' => 1, 'position' => 0, 'status' => 'completed']);
            $exercise->sets()->create(['set_number' => 1, 'actual_reps' => 10, 'actual_weight_kg' => $kind === 'own' ? 20 : 200, 'completed_at' => now()]);
            $this->plans[$kind] = $plan;
            $this->entries[$kind] = $entry;
        }
        $this->base = '/coach/trainees/'.$this->trainee->id;
        $this->actingAs($this->coach);
    }

    private function coach(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['account_type' => 'coach'])->save();
        $user->coachProfile()->create(['display_name' => 'Coach']);

        return $user;
    }

    private function connect(User $coach, User $trainee): CoachTraineeRelationship
    {
        $r = CoachTraineeRelationship::create(['coach_user_id' => $coach->id, 'trainee_user_id' => $trainee->id, 'status' => 'active', 'requested_at' => now(), 'approved_at' => now()]);
        $r->subscriptions()->create(['package_name' => 'Ongoing', 'starts_on' => today()->subDays(100), 'status' => 'active']);

        return $r;
    }

    private function props(string $path): array
    {
        return $this->get($path)->assertOk()->viewData('page')['props'];
    }

    public function test_coach_lists_calendar_history_and_overview_exclude_private_and_other_assignments(): void
    {
        $planId = $this->plans['own']->id;
        $this->assertSame([$planId], array_column($this->props($this->base.'/training-plans')['plans']['data'], 'id'));
        $this->assertSame([$planId], array_column($this->props($this->base.'/schedule-entries/create')['plans'], 'id'));
        $this->assertSame([$planId], array_column($this->props($this->base.'/training-history')['plans'], 'id'));
        foreach (['/calendar', '/training-history', '', '/progress'] as $path) {
            $props = $this->props($this->base.$path);
            $json = json_encode($props);
            foreach (['Secret private', 'Secret other', 'Workout private', 'Workout other', 'Day private', 'Day other'] as $secret) {
                $this->assertStringNotContainsString($secret, $json);
            }
        }
        $global = json_encode($this->props('/coach'));
        $this->assertStringNotContainsString('Workout private', $global);
        $this->assertStringNotContainsString('Workout other', $global);
        $this->assertCount(1, $this->props($this->base.'/calendar')['entries']);
        $this->assertSame(1, $this->props($this->base.'/training-history')['entries']['total']);
    }

    public function test_direct_routes_filter_ids_and_forged_schedule_writes_are_denied(): void
    {
        foreach (['private', 'other'] as $kind) {
            $plan = $this->plans[$kind];
            $entry = $this->entries[$kind];
            $this->get($this->base.'/training-plans/'.$plan->id)->assertStatus($kind === 'private' ? 403 : 404);
            $this->get($this->base.'/training-days/'.$plan->trainingDays->first()->id)->assertStatus($kind === 'private' ? 403 : 404);
            $this->get($this->base.'/schedule-entries/'.$entry->id)->assertNotFound();
            $this->get($this->base.'/training-history/'.$entry->id)->assertNotFound();
            $this->get($this->base.'/training-history?plan='.$plan->id)->assertNotFound();
            $this->post($this->base.'/schedule-entries', ['training_day_id' => $plan->trainingDays->first()->id, 'entry_type' => 'training', 'scheduled_date' => today()->addDay()->toDateString()])->assertSessionHasErrors('training_day_id');
        }
        $this->assertDatabaseCount('schedule_entries', 3);
    }

    public function test_plan_thumbnail_is_limited_to_this_coachs_assignment(): void
    {
        foreach ($this->plans as $kind => $plan) {
            $plan->trainingDays()->first()->exercises()->update(['thumbnail_path' => 'exercise-thumbnails/'.$kind.'.jpg']);
        }
        $rows = $this->props($this->base.'/training-plans')['plans']['data'];
        $this->assertCount(1, $rows);
        $this->assertSame(Storage::disk('public')->url('exercise-thumbnails/own.jpg'), $rows[0]['thumbnail_url']);
        $this->assertStringNotContainsString('private.jpg', json_encode($rows));
        $this->assertStringNotContainsString('other.jpg', json_encode($rows));
    }

    public function test_progress_uses_only_owned_assignments_and_personal_scope_keeps_all_results(): void
    {
        $props = $this->props($this->base.'/progress');
        $this->assertSame(1, $props['training']['completed']);
        $this->assertEquals(200, $props['training']['weighted_volume']);
        $this->assertEquals(20, $props['highlights'][0]['latest_weight']);
        $detail = $this->props($this->base.'/progress/exercises?exercise=builtin:privacy&mode=reps');
        $this->assertSame(1, $detail['history']['total']);
        $this->assertEquals(20, $detail['records'][0]['value']);
        $this->actingAs($this->trainee);
        $personal = $this->props('/progress');
        $this->assertSame(3, $personal['training']['completed']);
        $this->assertEquals(4200, $personal['training']['weighted_volume']);
    }

    public function test_owner_scheduled_assigned_workout_is_visible_and_detached_private_workout_is_not(): void
    {
        $own = $this->entries['own'];
        $own->update(['scheduled_by_user_id' => $this->trainee->id]);
        $this->get($this->base.'/schedule-entries/'.$own->id)->assertOk();
        $own->update(['workout_snapshot' => ['training_plan_id' => $this->plans['own']->id, 'exercises' => []], 'training_day_id' => null]);
        $this->get($this->base.'/training-history/'.$own->id)->assertOk();
        $own->update(['workout_snapshot' => ['training_plan_id' => $this->plans['private']->id, 'exercises' => []]]);
        $this->get($this->base.'/training-history/'.$own->id)->assertNotFound();
    }

    public function test_assignment_default_reuses_exact_template_and_explicit_copy_is_independent(): void
    {
        $source = $this->coach->trainingPlans()->create(['name' => 'Template', 'structure_type' => 'program', 'duration_weeks' => 1]);
        $source->forceFill(['workspace_type' => 'coach_template'])->save();
        $source->blocks()->create(['name' => 'Week', 'start_week' => 1, 'end_week' => 1, 'type' => 'normal', 'position' => 0]);
        $data = ['training_plan_id' => $source->id, 'confirmed' => true];
        $this->post($this->base.'/assign', $data)->assertSessionHasNoErrors();
        $copy = $this->relationship->assignments()->where('source_training_plan_id', $source->id)->firstOrFail();
        $this->post($this->base.'/assign', $data)->assertRedirect($this->base.'/training-plans/'.$copy->assigned_training_plan_id);
        $this->assertSame(1, $this->relationship->assignments()->where('source_training_plan_id', $source->id)->count());
        $list = $this->props('/coach/training-plans/'.$source->id.'/assign');
        $this->assertSame($copy->assigned_training_plan_id, $list['relationships']['data'][0]['existing_plan_id']);
        $this->post($this->base.'/assign', [...$data, 'another_copy' => true])->assertSessionHasNoErrors();
        $this->assertSame(2, $this->relationship->assignments()->where('source_training_plan_id', $source->id)->count());
    }

    public function test_assignment_list_is_paginated_searchable_and_queries_stay_bounded(): void
    {
        $template = $this->coach->trainingPlans()->create(['name' => 'Template']);
        $template->forceFill(['workspace_type' => 'coach_template'])->save();
        foreach (range(1, 26) as $i) {
            $this->connect($this->coach, User::factory()->create());
        }
        DB::enableQueryLog();
        $page = $this->props('/coach/training-plans/'.$template->id.'/assign');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertCount(25, $page['relationships']['data']);
        $this->assertSame(27, $page['relationships']['total']);
        $this->assertLessThan(35, $queries);
        $filtered = $this->props('/coach/training-plans/'.$template->id.'/assign?search='.$this->trainee->training_code);
        $this->assertSame(1, $filtered['relationships']['total']);
        $this->relationship->update(['status' => 'ended']);
        $this->get($this->base.'/progress')->assertNotFound();
    }

    public function test_progress_period_and_zero_weight_do_not_fabricate_weighted_metrics(): void
    {
        $entry = $this->entries['own'];
        $entry->sessionExercises->first()->sets()->update(['actual_weight_kg' => 0]);
        $props = $this->props($this->base.'/progress');
        $this->assertNull($props['training']['weighted_volume']);
        $this->assertEquals(10, $props['highlights'][0]['latest_reps']);
        $entry->update(['scheduled_date' => today()->subDays(50)]);
        $this->assertSame(0, $this->props($this->base.'/progress?range=30')['training']['completed']);
        $this->assertSame(1, $this->props($this->base.'/progress?range=90')['training']['completed']);
    }

    public function test_weight_history_pagination_keeps_exercise_highlights_and_pr_count_is_not_display_limit(): void
    {
        foreach (range(1, 12) as $i) {
            $this->trainee->bodyWeightEntries()->create(['weight_kg' => 80, 'recorded_date' => today()->subDays($i)]);
            $entry = $this->trainee->scheduleEntries()->create(['training_day_id' => $this->plans['own']->trainingDays()->first()->id, 'title' => 'Improvement', 'entry_type' => 'training', 'status' => 'completed', 'scheduled_date' => today()->subDays(13 - $i)]);
            $exercise = $entry->sessionExercises()->create(['name' => 'Progressive exercise', 'source_exercise_key' => 'builtin:improvement', 'tracking_mode' => 'reps', 'planned_sets' => 1, 'position' => 0, 'status' => 'completed']);
            $exercise->sets()->create(['set_number' => 1, 'actual_reps' => 10, 'actual_weight_kg' => 20 + $i, 'completed_at' => now()]);
        }
        $first = $this->props($this->base.'/progress');
        $second = $this->props($this->base.'/progress?page=2');
        $this->assertSame($first['highlights'], $second['highlights']);
        $this->assertSame(2, $second['entries']['current_page']);
        $this->assertSame(22, $first['recentRecordCount']);
        $this->assertCount(8, $first['recentRecords']);
    }

    public function test_current_assignment_prefers_running_program_over_new_unscheduled_copy(): void
    {
        $plan = $this->plans['own'];
        $plan->update(['structure_type' => 'program', 'duration_weeks' => 2]);
        $plan->programRuns()->create(['user_id' => $this->trainee->id, 'status' => 'active', 'started_on' => today(), 'start_token' => (string) Str::uuid(), 'definition_snapshot' => []]);
        $copy = $this->trainee->trainingPlans()->create(['name' => 'New unscheduled']);
        $copy->forceFill(['workspace_type' => 'coach_assigned'])->save();
        $this->relationship->assignments()->create(['coach_subscription_id' => $this->relationship->subscriptions()->first()->id, 'assigned_training_plan_id' => $copy->id, 'assigned_at' => now(), 'status' => 'active']);
        $row = app(CoachWorkspaceSummary::class)->query($this->coach)->where('trainee_user_id', $this->trainee->id)->first();
        $this->assertSame($plan->id, (int) $row->plan_id);
    }
}
