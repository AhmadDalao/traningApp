<?php

use App\Models\ProgramRun;
use App\Models\TrainingPlan;
use App\Models\User;
use App\Services\ProgramLifecycle;
use App\Services\ProgramSchedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'training_planner_test') {
    throw new RuntimeException('Isolated test database required');
}
config(['database.connections.program_race' => config('database.connections.mysql')]);
$other = DB::connection('program_race');
$user = User::create(['name' => 'Program race', 'email' => 'program-race-'.bin2hex(random_bytes(6)).'@example.test', 'password' => bin2hex(random_bytes(24))]);
$checks = [];
function programCheck(bool $valid, string $name): void
{
    global $checks;
    if (! $valid) {
        throw new RuntimeException($name);
    }
    $checks[] = $name;
    echo 'PASS: '.$name.PHP_EOL;
}
try {
    $plan = $user->trainingPlans()->create(['name' => 'Race program', 'structure_type' => 'program', 'duration_weeks' => 1]);
    $block = $plan->blocks()->create(['name' => 'Foundation', 'start_week' => 1, 'end_week' => 1, 'position' => 0, 'type' => 'normal']);
    $day = $plan->trainingDays()->create(['training_block_id' => $block->id, 'title' => 'Workout', 'type' => 'training', 'weekdays' => [0, 1, 2, 3, 4, 5, 6], 'default_time' => '18:00']);
    $exercise = $day->exercises()->create(['name' => 'Push-ups', 'sets' => 2, 'reps' => '10', 'tracking_mode' => 'reps', 'library_key' => 'builtin:Pushups', 'position' => 0]);
    $schedule = app(ProgramSchedule::class);
    $data = ['start_date' => today()->addDay()->toDateString(), 'nonce' => (string) Str::uuid()];
    $data['signature'] = $schedule->preview($plan, $data['start_date'], $data['nonce'])['signature'];
    DB::beginTransaction();
    DB::table('users')->count();
    // Commit another start after the first connection establishes its older snapshot.
    DB::setDefaultConnection('program_race');
    $run = DB::transaction(function () use ($plan, $user, $schedule, $data) {
        User::whereKey($user->id)->lockForUpdate()->firstOrFail();
        $plan = TrainingPlan::lockForUpdate()->findOrFail($plan->id);
        $paths = [];

        return $schedule->start($plan, $data, null, $paths);
    });
    DB::setDefaultConnection('mysql');
    User::whereKey($user->id)->lockForUpdate()->firstOrFail();
    $paths = [];
    $replay = $schedule->start(TrainingPlan::lockForUpdate()->findOrFail($plan->id), $data, null, $paths);
    programCheck($run->id === $replay->id, 'Start replay sees concurrent committed run under REPEATABLE READ');
    DB::commit();
    programCheck($plan->programRuns()->count() === 1 && $user->scheduleEntries()->count() === 7, 'Concurrent start retains exactly one run and one occurrence set');

    $run = ProgramRun::findOrFail($run->id);
    $preview = $schedule->changes($run, $plan);
    $entry = $run->sessions->first();
    DB::beginTransaction();
    DB::table('users')->count();
    $other->table('schedule_entries')->where('id', $entry->id)->update(['started_at' => now()]);
    $denied = false;
    try {
        $schedule->applyChanges(ProgramRun::lockForUpdate()->findOrFail($run->id), TrainingPlan::lockForUpdate()->findOrFail($plan->id), $preview['signature'], null, $paths);
    } catch (ValidationException $error) {
        $denied = true;
    }
    DB::rollBack();
    programCheck($denied && $entry->fresh()->started_at !== null, 'Future preview rejects a concurrently started workout');

    $freshData = ['start_date' => today()->addDay()->toDateString(), 'nonce' => (string) Str::uuid()];
    $freshData['signature'] = $schedule->preview($plan, $freshData['start_date'], $freshData['nonce'])['signature'];
    DB::beginTransaction();
    DB::table('users')->count();
    $other->table('training_exercises')->where('id', $exercise->id)->update(['sets' => 4]);
    $denied = false;
    try {
        $schedule->start(TrainingPlan::lockForUpdate()->findOrFail($plan->id), $freshData, null, $paths);
    } catch (ValidationException $error) {
        $denied = true;
    }
    DB::rollBack();
    programCheck($denied && $plan->programRuns()->count() === 1, 'Start rejects concurrently changed block prescription without partial data');

    DB::beginTransaction();
    DB::table('users')->count();
    $other->table('schedule_entries')->where('program_run_id', $run->id)->update(['started_at' => null, 'status' => 'completed', 'completed_at' => now()]);
    $done = app(ProgramLifecycle::class)->sync($run->id);
    DB::commit();
    programCheck($done->status === 'completed', 'Completion sees all concurrent session resolutions');
    $stamp = $done->completed_at->toDateTimeString();
    $done = app(ProgramLifecycle::class)->sync($run->id);
    programCheck($done->completed_at->toDateTimeString() === $stamp, 'Repeated completion synchronization preserves one completion timestamp');
    $other->table('schedule_entries')->where('id', $entry->id)->update(['status' => 'planned', 'completed_at' => null]);
    $open = app(ProgramLifecycle::class)->sync($run->id);
    programCheck($open->status === 'active' && $open->completed_at === null, 'Reopened session reopens run using current status');

    $preview = $schedule->changes($open, $plan->fresh());
    DB::beginTransaction();
    DB::table('users')->count();
    $other->table('schedule_entries')->where('id', $entry->id)->update(['scheduled_date' => today()->addDays(30), 'schedule_managed' => false]);
    $denied = false;
    try {
        $schedule->applyChanges(ProgramRun::lockForUpdate()->findOrFail($run->id), TrainingPlan::lockForUpdate()->findOrFail($plan->id), $preview['signature'], null, $paths);
    } catch (ValidationException $error) {
        $denied = true;
    }
    DB::rollBack();
    programCheck($denied && $entry->fresh()->scheduled_date->isSameDay(today()->addDays(30)), 'Concurrent manual move invalidates review and preserves moved date');

    $copy = $day->replicate();
    $copy->position = 1;
    $copy->save();
    $copy->exercises()->create($exercise->fresh()->only('name', 'sets', 'reps', 'tracking_mode', 'library_key', 'position'));
    $preview = $schedule->changes($open->fresh(), $plan->fresh());
    DB::beginTransaction();
    DB::table('users')->count();
    DB::setDefaultConnection('program_race');
    DB::transaction(function () use ($schedule, $run, $plan, $preview, &$paths) {
        $schedule->applyChanges(ProgramRun::lockForUpdate()->findOrFail($run->id), TrainingPlan::lockForUpdate()->findOrFail($plan->id), $preview['signature'], null, $paths);
    });
    DB::setDefaultConnection('mysql');
    $denied = false;
    try {
        $schedule->applyChanges(ProgramRun::lockForUpdate()->findOrFail($run->id), TrainingPlan::lockForUpdate()->findOrFail($plan->id), $preview['signature'], null, $paths);
    } catch (ValidationException $error) {
        $denied = true;
    }
    DB::rollBack();
    programCheck($denied && $run->sessions()->count() === 14, 'Concurrent repeated apply rejects stale review without duplicate additions');

    DB::beginTransaction();
    DB::table('users')->count();
    $other->table('schedule_entries')->where('program_run_id', $run->id)->update(['status' => 'completed', 'completed_at' => now()]);
    $done = app(ProgramLifecycle::class)->sync($run->id);
    DB::commit();
    programCheck($done->status === 'completed' && $done->completed_at !== null && $plan->programRuns()->count() === 1, 'Concurrent recompletion resolves the same run without another history record');
    echo count($checks).' program concurrency checks passed.'.PHP_EOL;
} finally {
    DB::setDefaultConnection('mysql');
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $user->delete();
}
