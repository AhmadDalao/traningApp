<?php

namespace Tests\Feature;

use App\Models\ScheduleEntry;
use App\Models\TrainingDay;
use App\Models\TrainingPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TrainingThumbnailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_covers_use_the_first_training_image_without_per_row_queries(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 12; $i++) {
            $plan = $user->trainingPlans()->create(['name' => 'Plan '.$i]);
            $rest = $plan->trainingDays()->create(['title' => 'Rest', 'type' => 'rest', 'position' => 0]);
            $rest->exercises()->create(['name' => 'Ignored', 'sets' => 1, 'reps' => '1', 'position' => 0, 'thumbnail_path' => 'rest.jpg']);
            $day = $plan->trainingDays()->create(['title' => 'Training', 'type' => 'training', 'position' => 1]);
            foreach ([null, '', 'first.jpg', 'second.jpg'] as $position => $path) {
                $day->exercises()->create(['name' => 'Exercise', 'sets' => 1, 'reps' => '1', 'position' => $position, 'thumbnail_path' => $path]);
            }
        }
        DB::enableQueryLog();
        $plans = TrainingPlan::withThumbnail()->get()->toArray();
        $this->assertCount(1, DB::getQueryLog());
        $this->assertCount(12, $plans);
        foreach ($plans as $plan) {
            $this->assertSame(Storage::disk('public')->url('first.jpg'), $plan['thumbnail_url']);
            $this->assertArrayNotHasKey('cover_thumbnail_path', $plan);
        }
        DB::flushQueryLog();
        $days = TrainingDay::withThumbnail()->get();
        foreach ($days as $day) {
            $this->assertSame($day->type === 'rest' ? null : Storage::disk('public')->url('first.jpg'), $day->thumbnail_url);
        }
        $this->assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_session_images_preserve_history_and_do_not_lazy_load_templates(): void
    {
        $day = new TrainingDay(['type' => 'training']);
        $day->setAttribute('cover_thumbnail_path', 'changed-template.jpg');
        $entry = new ScheduleEntry(['entry_type' => 'training', 'status' => 'planned']);
        DB::enableQueryLog();
        $this->assertNull($entry->thumbnail_url);
        $entry->setRelation('trainingDay', $day);
        $this->assertSame(Storage::disk('public')->url('changed-template.jpg'), $entry->thumbnail_url);
        $entry->program_prescription = ['exercises' => [['thumbnail_path' => 'prescription.jpg']]];
        $this->assertSame(Storage::disk('public')->url('prescription.jpg'), $entry->thumbnail_url);
        $entry->status = 'completed';
        $entry->workout_snapshot = ['exercises' => [['thumbnail_path' => null], ['thumbnail_path' => 'saved-workout.jpg']]];
        $this->assertSame(Storage::disk('public')->url('saved-workout.jpg'), $entry->thumbnail_url);
        $entry->workout_snapshot = ['exercises' => []];
        $this->assertNull($entry->thumbnail_url, 'An empty saved snapshot must not inherit a later template photo.');
        $entry->workout_snapshot = $entry->program_prescription = null;
        $this->assertNull($entry->thumbnail_url, 'Legacy completions have no reliable image to reconstruct.');
        $entry->status = 'planned';
        $entry->entry_type = 'rest';
        $this->assertNull($entry->thumbnail_url);
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
    }
}
