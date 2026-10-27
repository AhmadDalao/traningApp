<?php

namespace Database\Seeders;

use App\Models\TrainingDay;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PreviewSeeder extends Seeder
{
    private array $files = [];

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('Preview samples are only available locally.');

            return;
        }

        try {
            DB::transaction(function () {
                $this->populate('demo@example.com', 'Ahmad', 13, 110);
                $this->populate('maya.demo@example.com', 'Maya — Strength & Mobility', 4, 45);
            });
        } catch (\Throwable $error) {
            Storage::disk('public')->delete($this->files);
            throw $error;
        }
    }

    private function populate(string $email, string $name, int $planCount, int $pastDays): void
    {
        $user = User::firstOrCreate(['email' => $email], ['name' => $name, 'password' => 'Training123!']);
        if ($user->trainingPlans()->where('name', 'Sample · Full Body Foundation')->exists()) {
            $this->command?->info("Samples already exist for {$email}; existing edits were kept.");

            return;
        }

        $plans = [
            'Full Body Foundation', 'Push / Pull / Legs', 'Strength & Conditioning',
            'Running, Cycling & Mobility', 'Home Dumbbell Routine', 'Core & Stability',
            'Lower Body Strength', 'Upper Body Hypertrophy', 'Travel Week — Bodyweight',
            'قوة ومرونة · Strength & Mobility', 'A Fresh Start — Plan Your Next Block',
            'Extended Strength and Conditioning Programme for Busy Weeks, Early Mornings, and Consistent Full Body Training',
            'Layout Check — Long Workouts & Exercise Names',
        ];
        $exerciseNames = ['Back Squat', 'Dumbbell Romanian Deadlift', 'Split Squat', 'Seated Cable Row', 'Incline Dumbbell Press', 'Standing Calf Raise', 'Lat Pulldown', 'Lateral Raise', 'Dead Bug', 'Plank', 'Cycling', 'Treadmill Walk'];
        $focusAreas = ['Full Body', 'Legs', 'Back', 'Cardio', 'Shoulders', 'Core'];
        $icons = ['dumbbell', 'footprints', 'weight', 'bike', 'biceps', 'stretch', 'heart', 'target', 'bodyweight', 'waves', 'coffee', 'trophy', 'zap'];
        $training = collect();
        $rest = collect();
        $longDay = null;
        $notes = "Sample data for exploring the planner. These are fictional sessions you can edit or delete.\n\nFocus on a smooth, controlled movement. This longer note checks wrapping in cards, session details, and mobile forms.\n\nReference: https://example.test/".str_repeat('long-training-reference-', 8);

        foreach (array_slice($plans, 0, $planCount) as $p => $title) {
            $plan = $user->trainingPlans()->create([
                'name' => 'Sample · '.$title,
                'description' => $p === 11 ? str_repeat('A balanced example week with strength work, conditioning, and recovery. Adjust each day to suit your own routine. ', 12) : 'Sample plan for exploring training days, exercise icons, supersets, optional weights, and session history.',
            ]);
            // Keep one empty plan to make the existing empty state easy to inspect.
            if ($p === 10) {
                continue;
            }
            foreach (['Strength A', 'Conditioning & Core', 'Strength B', 'Rest & Recovery'] as $d => $label) {
                $isRest = $d === 3;
                $isLong = $p === 12 && $d === 0;
                $day = $plan->trainingDays()->create([
                    'title' => $isLong ? 'Extended Full Body Session — Strength, Conditioning, Mobility, and a Long Exercise List for Checking Smaller Screens' : $label.' · '.$title,
                    'type' => $isRest ? 'rest' : 'training', 'focus_area' => $isRest ? null : $focusAreas[($p + $d) % count($focusAreas)],
                    'icon' => $isRest ? 'coffee' : $icons[$p], 'notes' => $isLong ? $notes : 'Sample training day. Edit this reusable template to try the builder.', 'position' => $d,
                ]);
                if ($isRest) {
                    $rest->push($day);

                    continue;
                }
                $training->push($day);
                if ($isLong) {
                    $longDay = $day;
                }
                $count = $isLong ? 24 : 4 + ($p + $d) % 4;
                for ($e = 0; $e < $count; $e++) {
                    $exercise = $exerciseNames[($e + $p + $d) % count($exerciseNames)];
                    $bodyweight = in_array($exercise, ['Dead Bug', 'Plank', 'Cycling', 'Treadmill Walk']);
                    $thumbnail = null;
                    if ($e === 0 && $d === 0 && $p % 3 === 0) {
                        $thumbnail = 'exercise-thumbnails/preview-'.Str::uuid().'.jpg';
                        $this->files[] = $thumbnail;
                        Storage::disk('public')->put($thumbnail, file_get_contents(public_path('images/gym.jpg')));
                    }
                    $day->exercises()->create([
                        'name' => $isLong && $e === 0 ? 'Single-Leg Romanian Deadlift with Contralateral Dumbbell, a Controlled Lowering Phase, and a Pause at the Bottom of Every Repetition' : ($isLong && $e === 1 ? str_repeat('TempoControlledResistanceBand', 5) : $exercise),
                        'sets' => 2 + $e % 4, 'reps' => $isLong && $e === 0 ? '8–12 per side, with a controlled lowering phase and a brief pause before the next repetition' : ($bodyweight ? ['30 seconds', '60 seconds', '20 minutes'][$e % 3] : ['8–10', '10–12', '12–15'][$e % 3]),
                        'target_weight' => $bodyweight || $e % 5 === 4 ? null : [80, 22.5, 12.5, 45, 0, 100][$e % 6],
                        'icon' => $bodyweight ? 'activity' : $icons[($p + $e) % count($icons)],
                        'superset_group' => in_array($e, [1, 2]) ? 'A' : ($isLong && in_array($e, [4, 5, 6]) ? 'B' : null),
                        'thumbnail_path' => $thumbnail, 'notes' => $isLong && $e < 2 ? $notes : ($e % 3 === 0 ? 'Sample form reminder. Keep a steady tempo.' : null), 'position' => $e,
                    ]);
                }
            }
        }

        $serial = 0;
        for ($offset = -$pastDays; $offset <= 21; $offset++) {
            $day = $offset % 7 === 0 ? $rest->get($serial % $rest->count()) : $training->get($serial % $training->count());
            $status = $offset >= 0 ? 'planned' : (['skipped', 'planned', 'completed', 'completed', 'completed', 'completed', 'completed', 'skipped', 'completed'][$serial % 9]);
            $this->session($user, $day, $offset, $status, $serial++, $offset === 0 ? null : (sprintf('%02d:30', 6 + $serial % 14)));
        }
        // A deliberately busy day exposes calendar overflow and crowded Overview cards.
        for ($i = 0; $i < 9; $i++) {
            $day = $i === 0 && $longDay ? $longDay : $training->get(($i * 3) % $training->count());
            $this->session($user, $day, 0, $i < 2 ? 'completed' : ($i === 2 ? 'skipped' : 'planned'), $serial++, $i < 3 ? null : sprintf('%02d:00', 12 + $i));
        }
        for ($i = 0; $i < 6; $i++) {
            $this->session($user, $training->get(($i * 2) % $training->count()), 1, 'planned', $serial++, sprintf('%02d:15', 6 + $i * 2));
        }
        if ($longDay) {
            $this->session($user, $longDay, -1, 'completed', $serial++, '07:00');
        }
        $this->command?->info("Added {$planCount} sample plans and {$serial} sessions for {$email}.");
    }

    private function session(User $user, TrainingDay $day, int $offset, string $status, int $serial, ?string $time): void
    {
        $date = today()->addDays($offset);
        $entry = $user->scheduleEntries()->create([
            'training_day_id' => $day->id, 'title' => $day->title, 'entry_type' => $day->type, 'focus_area' => $day->focus_area, 'icon' => $day->icon,
            'scheduled_date' => $date, 'scheduled_time' => $time, 'status' => $status,
            'notes' => 'Sample session #'.($serial + 1).". Fictional preview data.\n".($serial % 5 === 0 ? str_repeat('A longer session note helps check line wrapping when viewing records on a phone. ', 12) : 'Use this entry to explore the calendar, session status, and training history.'),
        ]);
        if ($status !== 'completed') {
            return;
        }
        $snapshot = $entry->captureWorkoutSnapshot($this->files);
        foreach ($snapshot['exercises'] as $index => &$exercise) {
            if ($exercise['target_weight'] !== null && $serial % 4 !== 0 && ($serial % 4 === 1 || $index % 2 === 0)) {
                $exercise['actual_weight'] = number_format((float) $exercise['target_weight'] + ($serial % 3 === 0 ? 2.5 : 0), 2, '.', '');
            }
        }
        unset($exercise);
        $completed = $time ? $date->copy()->setTimeFromTimeString($time)->addHour() : $date->copy()->addHours(7);
        if ($completed->isFuture()) {
            $completed = now()->subMinutes(15);
        }
        $entry->update(['completed_at' => $completed, 'workout_snapshot' => $snapshot]);
    }
}
