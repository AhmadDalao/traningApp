<?php

use App\Http\Controllers\TrainingDayController;
use App\Http\Requests\UpdateTrainingDayRequest;
use App\Models\CoachTraineeRelationship;
use App\Models\TrainingDay;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
});
if (DB::connection()->getDatabaseName() !== 'training_planner_test') {
    throw new RuntimeException('Isolated test database required');
}
if (($argv[1] ?? '') === 'worker') {
    $job = json_decode(file_get_contents($argv[2]), true);
    $coach = User::findOrFail($job['coach']);
    $trainee = User::findOrFail($job['trainee']);
    $request = UpdateTrainingDayRequest::create('/coach/trainees/'.$trainee->id.'/training-days/'.$job['day'], 'PUT', ['schedule_only' => true, 'weekdays' => $job['weekdays'], 'default_time' => $job['time'], 'expected_weekdays' => [1, 3], 'expected_default_time' => '18:00']);
    $route = new Route(['PUT'], '/coach/trainees/{trainee}/training-days/{trainingDay}', fn () => null);
    $route->bind($request);
    $route->setParameter('trainingDay', TrainingDay::findOrFail($job['day']));
    $request->setRouteResolver(fn () => $route);
    $request->headers->set('Accept', 'application/json');
    $request->setUserResolver(fn () => $coach);
    $request->setLaravelSession(app('session')->driver());
    $request->attributes->set('coach_workspace', true);
    $request->attributes->set('training_owner', $trainee);
    app()->instance('request', $request);
    $request->setUserResolver(fn () => $coach);
    $request->setContainer(app())->setRedirector(app('redirect'));
    $request->validateResolved();
    while (microtime(true) < $job['at']) {
        usleep(1000);
    }
    try {
        app(TrainingDayController::class)->update($request, TrainingDay::findOrFail($job['day']));
        echo json_encode(['ok' => true, 'weekdays' => $job['weekdays'], 'time' => $job['time']]);
    } catch (ValidationException $e) {
        echo json_encode(['ok' => false, 'errors' => $e->errors()]);
    }
    exit;
}
$users = [];
$files = [];
$checks = 0;
function checkWeekly(bool $ok, string $label): void
{
    global $checks;
    if (! $ok) {
        throw new RuntimeException($label);
    }
    $checks++;
    echo 'PASS '.$label.PHP_EOL;
}
try {
    foreach (['coach', 'trainee'] as $role) {
        $users[$role] = User::create(['name' => 'Schedule race '.$role, 'email' => 'qa-weekly-'.bin2hex(random_bytes(8)).'@example.test', 'password' => bin2hex(random_bytes(24))]);
    }
    $coach = $users['coach'];
    $trainee = $users['trainee'];
    $coach->forceFill(['account_type' => 'coach'])->save();
    $coach->coachProfile()->create(['display_name' => 'Schedule race']);
    $relationship = CoachTraineeRelationship::create(['coach_user_id' => $coach->id, 'trainee_user_id' => $trainee->id, 'status' => 'active', 'requested_at' => now(), 'approved_at' => now()]);
    $subscription = $relationship->subscriptions()->create(['package_name' => 'Ongoing', 'starts_on' => today(), 'status' => 'active']);
    $plan = $trainee->trainingPlans()->create(['name' => 'Strength']);
    $plan->forceFill(['workspace_type' => 'coach_assigned'])->save();
    $relationship->assignments()->create(['assigned_training_plan_id' => $plan->id, 'coach_subscription_id' => $subscription->id, 'status' => 'active', 'assigned_at' => now()]);
    $day = $plan->trainingDays()->create(['title' => 'Upper', 'type' => 'training', 'weekdays' => [1, 3], 'default_time' => '18:00']);
    $exercise = $day->exercises()->create(['name' => 'Push-up', 'sets' => 3, 'reps' => '10', 'position' => 0]);
    $original = $exercise->fresh()->getAttributes();
    $session = $trainee->scheduleEntries()->create(['training_day_id' => $day->id, 'title' => 'Upper', 'entry_type' => 'training', 'status' => 'planned', 'scheduled_date' => today()->addWeek(), 'scheduled_time' => '18:00']);
    $before = $session->fresh()->getAttributes();
    $processes = [];
    $at = microtime(true) + 1;
    foreach ([[[2, 4], '19:00'], [[0, 6], '20:00']] as [$weekdays, $time]) {
        $file = tempnam(sys_get_temp_dir(), 'tp-weekly-');
        $files[] = $file;
        file_put_contents($file, json_encode(['coach' => $coach->id, 'trainee' => $trainee->id, 'day' => $day->id, 'at' => $at, 'weekdays' => $weekdays, 'time' => $time]));
        chmod($file, 0600);
        $process = new Process([PHP_BINARY, __FILE__, 'worker', $file]);
        $process->start();
        $processes[] = $process;
    }
    $results = [];
    foreach ($processes as $process) {
        $process->wait();
        checkWeekly($process->isSuccessful(), 'Independent weekly-edit process finished: '.$process->getErrorOutput());
        $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
    $accepted = array_values(array_filter($results, fn ($r) => $r['ok']));
    checkWeekly(count($accepted) === 1 && count(array_filter($results, fn ($r) => ! $r['ok'] && isset($r['errors']['schedule']))) === 1, 'Concurrent edits accept one complete choice and reject the stale choice');
    $day->refresh();
    checkWeekly($day->weekdays === $accepted[0]['weekdays'] && substr($day->default_time, 0, 5) === $accepted[0]['time'], 'No mixed weekdays/time or lost update');
    checkWeekly($exercise->fresh()->getAttributes() === $original && $session->fresh()->getAttributes() === $before, 'Editing weekly choices changes no exercise or Calendar occurrence before review');
    echo 'COMPLETE '.$checks.PHP_EOL;
} finally {
    foreach ($users as $user) {
        $user->delete();
    }
    foreach ($files as $file) {
        @unlink($file);
    }
}
