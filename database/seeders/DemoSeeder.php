<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }
        $user = User::firstOrCreate(['email' => 'demo@example.com'], ['name' => 'Ahmad', 'password' => 'Training123!']);
        if ($user->trainingPlans()->exists()) {
            return;
        }
        $plan = $user->trainingPlans()->create(['name' => 'Beginner Weekly Plan', 'description' => 'Build a strong foundation with a balanced mix of strength, cardio, and recovery. One session at a time.']);
        $definitions = [
            ['Leg Day', 'Legs', [['Back Squat', 4, '8-10', null], ['Leg Press', 3, '10-12', null], ['Leg Extension', 3, '12', 'A'], ['Leg Curl', 3, '12', 'A'], ['Calf Raise', 4, '15', null]]],
            ['Shoulder Day', 'Shoulders', [['Shoulder Press', 4, '8-10', null], ['Lateral Raise', 3, '12-15', 'A'], ['Front Raise', 3, '12-15', 'A']]],
            ['Cardio', 'Cardio', [['Treadmill', 1, '20 minutes', null], ['Jumping Jacks', 3, '1 minute', null], ['Plank', 3, '30 seconds', null]]],
            ['Rest Day', null, []],
            ['Back Day', 'Back', [['Lat Pulldown', 3, '10-12', null], ['Seated Row', 3, '12', null], ['Face Pull', 3, '15', null]]],
        ];
        $days = [];
        foreach ($definitions as $position => [$title, $focus, $exercises]) {
            $day = $plan->trainingDays()->create(['title' => $title, 'type' => $focus ? 'training' : 'rest', 'focus_area' => $focus, 'notes' => $focus ? 'Keep your movements controlled. Focus on form and steady progress.' : 'Take it easy and give yourself time to recover.', 'position' => $position]);
            foreach ($exercises as $i => [$name, $sets, $reps, $group]) {
                $day->exercises()->create(['name' => $name, 'sets' => $sets, 'reps' => $reps, 'superset_group' => $group, 'position' => $i]);
            }
            $days[$title] = $day;
        }
        foreach ([['Leg Day', 0, 'planned'], ['Cardio', 0, 'completed'], ['Back Day', -1, 'completed'], ['Cardio', -2, 'skipped'], ['Rest Day', 1, 'planned'], ['Shoulder Day', 2, 'planned'], ['Cardio', 3, 'planned'], ['Back Day', 4, 'planned'], ['Leg Day', 6, 'planned']] as [$title, $offset, $status]) {
            $day = $days[$title];
            $user->scheduleEntries()->create(['training_day_id' => $day->id, 'title' => $title, 'entry_type' => $day->type, 'focus_area' => $day->focus_area, 'scheduled_date' => today()->addDays($offset), 'status' => $status, 'notes' => $title === 'Leg Day' ? 'Evening session. Start with a gentle warm-up.' : null]);
        }
    }
}
