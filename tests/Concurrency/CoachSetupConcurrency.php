<?php

use App\Http\Controllers\CoachSetupController;
use App\Models\CoachTraineeRelationship;
use App\Models\TrainingPlan;
use App\Models\User;
use App\Services\CoachingSubscriptions;
use App\Services\PlanSchedule;
use App\Services\ProgramSchedule;
use App\Services\TrainingPlanDuplicator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'training_planner_test') {
    throw new RuntimeException('Isolated database required');
}
if (($argv[1] ?? '') === 'worker') {
    [$mode, $coachId, $traineeId, $templateId] = array_slice($argv, 2);
    $coach = User::findOrFail($coachId);
    $trainee = User::findOrFail($traineeId);
    if ($mode === 'coaching') {
        app(CoachingSubscriptions::class)->start($coach, $trainee, ['package_name' => 'Race', 'starts_on' => today()->toDateString(), 'open_ended' => true], true);
    } elseif ($mode === 'training') {
        app(TrainingPlanDuplicator::class)->assign($coach, $trainee, TrainingPlan::findOrFail($templateId), [], true);
    } else {
        $data = json_decode(file_get_contents($templateId), true);
        $request = Request::create('/setup/activate', 'POST', $data);
        $request->setUserResolver(fn () => $coach);
        $request->attributes->set('training_owner', $trainee);
        $request->attributes->set('coach_workspace', true);
        $app->instance('request', $request);
        $request->setUserResolver(fn () => $coach);
        $controller = app(CoachSetupController::class);
        $controller->activate($request, app(ProgramSchedule::class), app(PlanSchedule::class));
    }
    echo 'WORKER_OK';
    exit;
}
$coach = User::create(['name' => 'QA setup coach', 'email' => 'qa-setup-'.bin2hex(random_bytes(8)).'@example.test', 'password' => bin2hex(random_bytes(20)), 'account_type' => 'coach']);
$coach->forceFill(['account_type' => 'coach'])->save();
$coach->coachProfile()->create(['display_name' => 'Coach']);
$trainee = User::create(['name' => 'QA setup trainee', 'email' => 'qa-setup-'.bin2hex(random_bytes(8)).'@example.test', 'password' => bin2hex(random_bytes(20))]);
$checks = 0;
$file = tempnam(sys_get_temp_dir(), 'tp-setup-');
function checkSetup(bool $ok, string $label): void
{
    global $checks;
    if (! $ok) {
        throw new RuntimeException($label);
    } $checks++;
    echo 'PASS '.$label.PHP_EOL;
}
$race = function ($mode, $id = '') use ($coach, $trainee) {
    $a = new Process([PHP_BINARY, __FILE__, 'worker', $mode, (string) $coach->id, (string) $trainee->id, (string) $id]);
    $b = new Process([PHP_BINARY, __FILE__, 'worker', $mode, (string) $coach->id, (string) $trainee->id, (string) $id]);
    $a->start();
    $b->start();
    $a->wait();
    $b->wait();
    checkSetup($a->isSuccessful() && $b->isSuccessful() && str_contains($a->getOutput(), 'WORKER_OK') && str_contains($b->getOutput(), 'WORKER_OK'), 'Two '.$mode.' processes succeed: '.$a->getOutput().$b->getOutput().$a->getErrorOutput().$b->getErrorOutput());
};
try {
    $relation = CoachTraineeRelationship::create(['coach_user_id' => $coach->id, 'trainee_user_id' => $trainee->id, 'status' => 'active', 'requested_at' => now()]);
    $race('coaching');
    checkSetup($relation->subscriptions()->count() === 1, 'Concurrent coaching step creates one subscription');
    $plan = $coach->trainingPlans()->create(['name' => 'Setup program', 'structure_type' => 'program', 'duration_weeks' => 1]);
    $plan->forceFill(['workspace_type' => 'coach_template'])->save();
    $block = $plan->blocks()->create(['name' => 'Foundation', 'start_week' => 1, 'end_week' => 1, 'position' => 0, 'type' => 'normal']);
    $day = $plan->trainingDays()->create(['training_block_id' => $block->id, 'title' => 'Workout', 'type' => 'training', 'weekdays' => [1, 3, 5], 'default_time' => '18:00']);
    $day->exercises()->create(['name' => 'Push-ups', 'sets' => 2, 'reps' => '10', 'position' => 0]);
    $race('training', $plan->id);
    checkSetup($relation->assignments()->count() === 1 && $trainee->trainingPlans()->count() === 1, 'Concurrent training step creates one independent assignment');
    $copy = $relation->assignments()->first()->plan;
    $data = ['training_plan_id' => $copy->id, 'start_date' => today()->addDay()->toDateString(), 'nonce' => (string) Str::uuid(), 'confirmed' => true];
    $data['signature'] = app(ProgramSchedule::class)->preview($copy, $data['start_date'], $data['nonce'], $coach)['signature'];
    file_put_contents($file, json_encode($data));
    chmod($file, 0600);
    $race('activate', $file);
    checkSetup($copy->programRuns()->count() === 1, 'Concurrent activation creates one program run');
    checkSetup($trainee->scheduleEntries()->count() === 3 && $trainee->scheduleEntries()->distinct()->count('plan_occurrence_key') === 3, 'Concurrent activation creates one occurrence set');
} finally {
    unlink($file);
    $coach->delete();
    $trainee->delete();
}
echo 'COMPLETE '.$checks.PHP_EOL;
