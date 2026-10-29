<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ProgramDefinition;
use App\Services\ProgramSchedule;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProgramMediaCleanupTest extends TestCase
{
    use DatabaseMigrations;

    public function test_committed_block_deletion_removes_template_media_and_preserves_run_media(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $plan = $user->trainingPlans()->create(['name' => 'Media lifecycle', 'structure_type' => 'program', 'duration_weeks' => 1]);
        $block = $plan->blocks()->create(['name' => 'Original', 'start_week' => 1, 'end_week' => 1, 'position' => 0, 'type' => 'normal']);
        $day = $plan->trainingDays()->create(['training_block_id' => $block->id, 'title' => 'Push', 'type' => 'training', 'weekdays' => [1]]);
        Storage::disk('public')->put('exercise-thumbnails/original.jpg', 'owned image');
        $day->exercises()->create(['name' => 'Push-ups', 'sets' => 2, 'reps' => '10', 'tracking_mode' => 'reps', 'thumbnail_path' => 'exercise-thumbnails/original.jpg', 'position' => 0]);
        $data = ['start_date' => today()->addDay()->toDateString(), 'nonce' => (string) Str::uuid()];
        $schedule = app(ProgramSchedule::class);
        $data['signature'] = $schedule->preview($plan, $data['start_date'], $data['nonce'])['signature'];
        $paths = [];
        $run = DB::transaction(fn () => $schedule->start($plan, $data, null, $paths));
        $savedPath = $run->sessions()->first()->program_prescription['exercises'][0]['thumbnail_path'];
        $this->assertNotSame('exercise-thumbnails/original.jpg', $savedPath);
        DB::transaction(fn () => app(ProgramDefinition::class)->saveBlocks($plan, [['name' => 'Replacement', 'start_week' => 1, 'end_week' => 1, 'type' => 'normal']], 1, false, $paths));
        Storage::disk('public')->assertMissing('exercise-thumbnails/original.jpg');
        Storage::disk('public')->assertExists($savedPath);
        $this->assertNull($run->sessions()->first()->training_day_id);
        $this->assertSame('Original', $run->fresh()->definition_snapshot['blocks'][0]['name']);
    }
}
