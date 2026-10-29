<?php

use App\Models\ProgramRun;
use App\Models\User;
use App\Services\ProgramControl;
use App\Services\ProgramLifecycle;
use App\Services\ProgramSchedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'training_planner_test') {
    throw new RuntimeException('Isolated test database required');
}
config(['database.connections.program_race' => config('database.connections.mysql')]);
$other = DB::connection('program_race');
$user = User::create(['name' => 'Program race', 'email' => 'control-race-'.bin2hex(random_bytes(6)).'@example.test', 'password' => bin2hex(random_bytes(24))]);
$checks = [];
function controlCheck(bool $valid, string $name): void
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
    $control = app(ProgramControl::class);
    $run = DB::transaction(function () use ($schedule, $plan, $data) {
        $paths = [];

        return $schedule->start($plan, $data, null, $paths);
    });
    $today = today()->toDateString();
    $future = today()->addDays(20)->toDateString();
    foreach (['pause', 'resume', 'shift', 'end'] as $action) {
        $run->update(['status' => $action === 'resume' ? 'paused' : 'active', 'paused_from' => $action === 'resume' ? $today : null, 'resume_on' => null]);
        $payload = ['action' => $action, 'pause_date' => $today, 'start_date' => $action === 'shift' ? today()->addDays(30)->toDateString() : $future];
        $preview = DB::transaction(fn () => $control->preview(ProgramRun::lockForUpdate()->findOrFail($run->id), $payload));
        DB::beginTransaction();
        DB::table('users')->count(); // Establish a stale REPEATABLE READ snapshot.
        $entry = $run->sessions()->first();
        $other->table('schedule_entries')->where('id', $entry->id)->update(['notes' => 'Concurrent '.$action]);
        $denied = false;
        try {
            $control->apply(ProgramRun::lockForUpdate()->findOrFail($run->id), $payload, $preview['signature']);
        } catch (ValidationException $e) {
            $denied = true;
        }
        DB::rollBack();
        controlCheck($denied, $action.' rejects concurrent session edits despite old transaction snapshot');
        $preview = DB::transaction(fn () => $control->preview(ProgramRun::lockForUpdate()->findOrFail($run->id), $payload));
        DB::beginTransaction();
        DB::table('users')->count();
        DB::setDefaultConnection('program_race');
        DB::transaction(fn () => $control->apply(ProgramRun::lockForUpdate()->findOrFail($run->id), $payload, $preview['signature']));
        DB::setDefaultConnection('mysql');
        $denied = false;
        try {
            $control->apply(ProgramRun::lockForUpdate()->findOrFail($run->id), $payload, $preview['signature']);
        } catch (ValidationException|HttpException $e) {
            $denied = true;
        }
        DB::rollBack();
        controlCheck($denied, 'Concurrent double '.$action.' cannot reapply changes');
    }
    controlCheck($run->fresh()->status === 'ended' && $run->sessions()->count() === 0, 'Double end keeps one ended run with no remaining eligible occurrences');
    controlCheck(app(ProgramLifecycle::class)->sync($run->id)->status === 'ended', 'Ended run cannot be reopened by lifecycle synchronization');

    $data['nonce'] = (string) Str::uuid();
    $data['signature'] = $schedule->preview($plan, $data['start_date'], $data['nonce'])['signature'];
    $paths = [];
    $run = DB::transaction(fn () => $schedule->start($plan, $data, null, $paths));
    $payload = ['action' => 'end'];
    $preview = DB::transaction(fn () => $control->preview(ProgramRun::lockForUpdate()->findOrFail($run->id), $payload));
    DB::beginTransaction();
    DB::table('users')->count();
    $other->table('schedule_entries')->where('program_run_id', $run->id)->update(['status' => 'completed', 'completed_at' => now()]);
    $denied = false;
    try {
        $control->apply(ProgramRun::lockForUpdate()->findOrFail($run->id), $payload, $preview['signature']);
    } catch (HttpException $e) {
        $denied = true;
    }
    DB::rollBack();
    controlCheck($denied && app(ProgramLifecycle::class)->sync($run->id)->status === 'completed', 'Completion winning the race cannot be overwritten by End');
    controlCheck($run->sessions()->where('status', 'completed')->count() === 7, 'Concurrent completion preserves all completed sessions');

    // An overlapping writer must wait on the same run/session locks, not bypass the preview.
    $run->update(['status' => 'active', 'completed_at' => null]);
    $run->sessions()->update(['status' => 'planned', 'completed_at' => null]);
    $preview = DB::transaction(fn () => $control->preview(ProgramRun::lockForUpdate()->findOrFail($run->id), $payload));
    DB::beginTransaction();
    $locked = ProgramRun::lockForUpdate()->findOrFail($run->id);
    $control->preview($locked, $payload);
    $other->statement('SET SESSION innodb_lock_wait_timeout = 1');
    $blocked = false;
    try {
        $other->table('schedule_entries')->where('program_run_id', $run->id)->update(['status' => 'completed']);
    } catch (QueryException $e) {
        $blocked = ($e->errorInfo[1] ?? null) === 1205;
    }
    controlCheck($blocked, 'Overlapping completion writer blocks behind the control session locks');
    $control->apply($locked, $payload, $preview['signature']);
    DB::commit();
    controlCheck($other->table('schedule_entries')->where('program_run_id', $run->id)->update(['status' => 'completed']) === 0, 'End winning the race leaves no eligible session for a late completion to mutate');
    controlCheck($run->fresh()->status === 'ended' && $run->fresh()->completed_at === null, 'End and completion have distinct timestamps after the race');
    echo count($checks).' program control concurrency checks passed.'.PHP_EOL;
} finally {
    DB::setDefaultConnection('mysql');
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $user->delete();
}
