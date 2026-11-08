<?php

use App\Models\CoachTraineeRelationship;
use App\Models\TrainingPlan;
use App\Models\User;
use App\Services\CoachSetup;
use App\Services\TrainingPlanDuplicator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'training_planner_test') {
    throw new RuntimeException('Isolated database required');
}
if (($argv[1] ?? '') === 'worker') {
    $job = json_decode(file_get_contents($argv[2]), true);
    while (microtime(true) < $job['at']) {
        usleep(1000);
    }
    try {
        $result = $job['mode'] === 'assign'
            ? app(TrainingPlanDuplicator::class)->assign(User::findOrFail($job['coach']), User::findOrFail($job['trainee']), TrainingPlan::findOrFail($job['input']['template']), [], resumeSetup: true)->id
            : app(CoachSetup::class)->{$job['mode']}(User::findOrFail($job['coach']), User::findOrFail($job['trainee']), $job['input']);
        echo json_encode(['status' => 'ok', 'result' => $result]);
    } catch (ValidationException|HttpExceptionInterface|ModelNotFoundException $error) {
        echo json_encode(['status' => 'rejected', 'code' => $error instanceof ValidationException ? 422 : ($error instanceof HttpExceptionInterface ? $error->getStatusCode() : 404)]);
    }
    exit;
}
$checks = 0;
$users = [];
$files = [];
function checkSetup(bool $ok, string $label): void
{
    global $checks;
    if (! $ok) {
        throw new RuntimeException($label);
    }
    $checks++;
    echo 'PASS '.$label.PHP_EOL;
}
$fixture = function () use (&$users) {
    $coach = User::create(['name' => 'QA setup coach', 'email' => 'qa-setup-'.bin2hex(random_bytes(8)).'@example.test', 'password' => bin2hex(random_bytes(20))]);
    $coach->forceFill(['account_type' => 'coach'])->save();
    $coach->coachProfile()->create(['display_name' => 'Coach']);
    $trainee = User::create(['name' => 'QA setup trainee', 'email' => 'qa-setup-'.bin2hex(random_bytes(8)).'@example.test', 'password' => bin2hex(random_bytes(20))]);
    array_push($users, $coach, $trainee);
    $relation = CoachTraineeRelationship::create(['coach_user_id' => $coach->id, 'trainee_user_id' => $trainee->id, 'status' => 'active', 'requested_at' => now()]);
    $plan = $coach->trainingPlans()->create(['name' => 'Setup program', 'structure_type' => 'program', 'duration_weeks' => 1]);
    $plan->forceFill(['workspace_type' => 'coach_template'])->save();
    $block = $plan->blocks()->create(['name' => 'Foundation', 'start_week' => 1, 'end_week' => 1, 'position' => 0, 'type' => 'normal']);
    $day = $plan->trainingDays()->create(['training_block_id' => $block->id, 'title' => 'Workout', 'type' => 'training', 'weekdays' => [1, 3, 5], 'default_time' => '18:00']);
    $day->exercises()->create(['name' => 'Push-ups', 'sets' => 2, 'reps' => '10', 'position' => 0]);

    return [$coach, $trainee, $relation, $plan];
};
$review = function ($f, array $changes = [], bool $begin = false) {
    [$coach, $trainee, $relationship, $plan] = $f;
    $service = app(CoachSetup::class);
    $public = $service->publicDraft($relationship->fresh());
    $choices = array_replace($public['choices'], ['selection_type' => 'template', 'training_plan_id' => $plan->id, 'start_date' => today()->addWeek()->toDateString(), 'open_ended' => true], $changes);
    $draft = $service->save($coach, $trainee, ['revision' => $public['revision'], 'choices' => $choices, 'begin' => $begin]);
    $preview = $service->preview($coach, $trainee, $draft['revision'], $draft['token']);
    checkSetup($preview['valid'], 'Race starts from valid reviewed draft');

    return ['revision' => $draft['revision'], 'token' => $draft['token'], 'signature' => $preview['signature'], 'confirmed' => true];
};
$race = function ($f, array $jobs) use (&$files) {
    $processes = [];
    $at = microtime(true) + .8;
    foreach ($jobs as [$mode, $input]) {
        $file = tempnam(sys_get_temp_dir(), 'tp-setup-');
        $files[] = $file;
        file_put_contents($file, json_encode(['at' => $at, 'mode' => $mode, 'input' => $input, 'coach' => $f[0]->id, 'trainee' => $f[1]->id]));
        chmod($file, 0600);
        $process = new Process([PHP_BINARY, __FILE__, 'worker', $file]);
        $process->start();
        $processes[] = $process;
    }

    return array_map(function ($process) {
        $process->wait();
        checkSetup($process->isSuccessful(), 'Independent PHP process finished: '.$process->getErrorOutput());
        try {
            return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException($process->getOutput().$process->getErrorOutput());
        }
    }, $processes);
};
try {
    $f = $fixture();
    $f[2]->subscriptions()->create(['package_name' => 'Ongoing', 'starts_on' => today(), 'status' => 'active']);
    $results = $race($f, [['assign', ['template' => $f[3]->id]], ['assign', ['template' => $f[3]->id]]]);
    checkSetup($results[0]['status'] === 'ok' && $results[0] === $results[1] && $f[2]->assignments()->count() === 1 && $f[1]->trainingPlans()->count() === 1, 'Concurrent default Assign reuses exactly one independent copy');
    $f = $fixture();
    $input = $review($f);
    $results = $race($f, [['activate', $input], ['activate', $input]]);
    checkSetup($results[0]['status'] === 'ok' && $results[0] == $results[1], 'Double Activate returns the identical receipt');
    checkSetup($f[2]->subscriptions()->count() === 1 && $f[2]->assignments()->count() === 1 && $f[1]->trainingPlans()->count() === 1, 'Exactly one subscription, assignment and independent copy');
    $copy = $f[2]->assignments()->first()->plan;
    checkSetup($copy->programRuns()->count() === 1 && $f[1]->scheduleEntries()->count() === 3 && $f[1]->scheduleEntries()->distinct()->count('plan_occurrence_key') === 3, 'Exactly one run and occurrence set');
    $subscription = $f[2]->subscriptions()->first();
    $subscription->update(['status' => 'paused']);
    $input = $review($f, begin: true);
    $results = $race($f, [['activate', $input], ['activate', $input]]);
    checkSetup($results[0]['status'] === 'ok' && $results[0] == $results[1] && $f[2]->subscriptions()->count() === 1 && $subscription->fresh()->status === 'active', 'Double Resume reuses the same period and receipt');
    checkSetup($copy->programRuns()->count() === 1 && $f[1]->scheduleEntries()->count() === 3, 'Resume does not restart or duplicate training');
    $subscription->update(['status' => 'ended']);
    $before = $subscription->fresh()->getAttributes();
    $input = $review($f, begin: true);
    $results = $race($f, [['activate', $input], ['activate', $input]]);
    checkSetup($results[0]['status'] === 'ok' && $results[0] == $results[1] && $f[2]->subscriptions()->count() === 2, 'Double Renew creates exactly one new period');
    checkSetup($before === $subscription->fresh()->getAttributes(), 'Renew preserves the historical period');
    checkSetup($copy->programRuns()->count() === 1 && $f[1]->scheduleEntries()->count() === 3, 'Renew preserves the existing run and schedule');

    $f = $fixture();
    $old = $review($f);
    $public = app(CoachSetup::class)->publicDraft($f[2]->fresh());
    $a = ['revision' => $public['revision'], 'choices' => [...$public['choices'], 'start_date' => today()->addWeeks(2)->toDateString()]];
    $b = ['revision' => $public['revision'], 'choices' => [...$public['choices'], 'start_date' => today()->addWeeks(3)->toDateString()]];
    $results = $race($f, [['save', $a], ['save', $b]]);
    checkSetup(count(array_filter($results, fn ($r) => $r['status'] === 'ok')) === 1 && count(array_filter($results, fn ($r) => ($r['code'] ?? null) === 409)) === 1, 'Concurrent draft edits accept exactly one revision');
    $draft = app(CoachSetup::class)->publicDraft($f[2]->fresh());
    $preview = app(CoachSetup::class)->preview($f[0], $f[1], $draft['revision'], $draft['token']);
    $new = ['revision' => $draft['revision'], 'token' => $draft['token'], 'signature' => $preview['signature'], 'confirmed' => true];
    $results = $race($f, [['activate', $old], ['activate', $new]]);
    checkSetup($results[1]['status'] === 'ok', 'Current reviewed revision commits during old/new activation race');
    $run = $f[2]->assignments()->first()->plan->programRuns()->first();
    checkSetup($run->started_on->toDateString() === $draft['choices']['start_date'] && $f[2]->subscriptions()->count() === 1 && $f[1]->scheduleEntries()->count() === 3, 'No mixed revision, duplicate subscription or occurrence set');

    // v2.3.9: a deliberate second program uses the same atomic coordinator.
    $f = $fixture();
    $input = $review($f);
    app(CoachSetup::class)->activate($f[0], $f[1], $input);
    $oldToken = $input['token'];
    $newTemplate = app(TrainingPlanDuplicator::class)->plan($f[0], $f[3]);
    $next = $review($f, ['training_plan_id' => $newTemplate->id], true);
    checkSetup($oldToken !== $next['token'], 'Intentional new assignment rotates the completed activation token');
    $results = $race($f, [['activate', $next], ['activate', $next]]);
    checkSetup($results[0]['status'] === 'ok' && $results[0] === $results[1], 'Double Assign & Schedule for the next program returns one receipt');
    checkSetup($f[2]->subscriptions()->count() === 1 && $f[2]->assignments()->count() === 2 && $f[1]->scheduleEntries()->count() === 6, 'Second assignment preserves the original schedule and reuses coaching coverage');

    $f = $fixture();
    $input = $review($f);
    $f[2]->update(['status' => 'disconnected']);
    $results = $race($f, [['activate', $input], ['activate', $input]]);
    checkSetup($results[0]['status'] === 'rejected' && $results[1]['status'] === 'rejected' && $f[2]->subscriptions()->count() === 0 && $f[2]->assignments()->count() === 0, 'Revoked consent blocks both activation processes without partial state');
} finally {
    foreach ($files as $file) {
        @unlink($file);
    }
    foreach ($users as $user) {
        $user->delete();
    }
}
echo 'COMPLETE '.$checks.PHP_EOL;
