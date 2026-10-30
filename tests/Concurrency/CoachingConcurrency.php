<?php

use App\Http\Controllers\CoachConnectionController;
use App\Http\Controllers\CoachScheduleController;
use App\Http\Controllers\CoachSubscriptionController;
use App\Http\Controllers\PlanScheduleController;
use App\Http\Controllers\TrainingPlanController;
use App\Http\Requests\UpdateTrainingPlanRequest;
use App\Models\CoachPairingToken;
use App\Models\CoachTraineeRelationship;
use App\Models\User;
use App\Services\CoachAccess;
use App\Services\CoachingSubscriptions;
use App\Services\ExerciseProgression;
use App\Services\PlanSchedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

set_exception_handler(function (Throwable $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
});
if (DB::connection()->getDatabaseName() !== 'training_planner_test') {
    throw new RuntimeException('Isolated test database required');
}
config(['database.connections.coach_race' => config('database.connections.mysql')]);
$other = DB::connection('coach_race');
$results = [];
function check($ok, $name)
{
    global $results;
    if (! $ok) {
        throw new RuntimeException($name);
    }$results[] = $name;
    echo "PASS: $name\n";
}
function req($data = [], $class = Request::class)
{
    global $coach,$trainee;
    $r = $class::create('/coach/trainees/'.$trainee->id, 'POST', $data);
    $r->setUserResolver(fn () => $coach);
    $r->setLaravelSession(app('session')->driver());
    $r->attributes->set('coach_workspace', true);
    $r->attributes->set('training_owner', $trainee);
    app()->instance('request', $r);
    $r->setUserResolver(fn () => $coach);
    if ($r instanceof FormRequest) {
        $r->setContainer(app())->setRedirector(app('redirect'));
        $r->validateResolved();
    }

    return $r;
}
function race($commit, $mutation, $name, $denied = true)
{
    global $other;
    DB::beginTransaction();
    DB::table('users')->count();
    try {
        $commit($other);
        $mutation();
        if ($denied) {
            throw new RuntimeException('Mutation succeeded: '.$name);
        }check(true, $name);
    } catch (ModelNotFoundException|ValidationException|HttpExceptionInterface $e) {
        if (! $denied) {
            throw $e;
        }check(true, $name);
    } finally {
        DB::rollBack();
    }
}
$coach = User::create(['name' => 'Concurrency coach', 'email' => 'v16-race-coach@example.test', 'password' => bin2hex(random_bytes(24))]);
$coach->forceFill(['account_type' => 'coach'])->save();
$coach->coachProfile()->create(['display_name' => 'Race coach']);
$trainee = User::create(['name' => 'Concurrency trainee', 'email' => 'v16-race-trainee@example.test', 'password' => bin2hex(random_bytes(24))]);
try {
    $access = app(CoachAccess::class);
    $connection = app(CoachConnectionController::class);
    $r = req(['training_code' => $trainee->training_code]);
    race(function ($db) use ($coach, $trainee) {
        $db->table('coach_trainee_relationships')->insert(['coach_user_id' => $coach->id, 'trainee_user_id' => $trainee->id, 'status' => 'pending', 'requested_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }, fn () => $connection->store($r, $access), 'Request sees concurrently committed pending relationship', false);
    check(CoachTraineeRelationship::where('coach_user_id', $coach->id)->count() === 1, 'No duplicate request');
    $relation = CoachTraineeRelationship::where('coach_user_id', $coach->id)->firstOrFail();
    $relation->update(['status' => 'active', 'approved_at' => now()]);
    $r = req(['relationship_id' => $relation->id, 'package_name' => 'New', 'starts_on' => today()->toDateString(), 'open_ended' => true]);
    race(fn ($db) => $db->table('coach_subscriptions')->insert(['coach_trainee_relationship_id' => $relation->id, 'package_name' => 'Concurrent', 'starts_on' => today(), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]), fn () => app(CoachSubscriptionController::class)->store($r, app(CoachingSubscriptions::class)), 'Subscription uniqueness uses current locking read');
    check($relation->subscriptions()->count() === 1, 'One open subscription');
    $sub = $relation->subscriptions()->firstOrFail();
    $raw = bin2hex(random_bytes(32));
    $token = CoachPairingToken::create(['trainee_user_id' => $trainee->id, 'token_hash' => hash('sha256', $raw), 'expires_at' => now()->addMinutes(10)]);
    $r = req(['token' => $raw]);
    race(fn ($db) => $db->table('coach_pairing_tokens')->where('id', $token->id)->update(['used_at' => now()]), fn () => $connection->store($r, $access), 'Concurrent QR consumption blocks replay after stale precheck');
    $plan = $trainee->trainingPlans()->create(['name' => 'Assigned', 'start_date' => today()->addDay(), 'end_date' => today()->addDays(3)]);
    $plan->forceFill(['workspace_type' => 'coach_assigned'])->save();
    $relation->assignments()->create(['assigned_training_plan_id' => $plan->id, 'coach_subscription_id' => $sub->id, 'assigned_at' => now(), 'status' => 'active']);
    $day = $plan->trainingDays()->create(['title' => 'Workout', 'type' => 'training', 'weekdays' => [0, 1, 2, 3, 4, 5, 6], 'default_time' => '18:00']);
    $exercise = $day->exercises()->create(['name' => 'Squat', 'sets' => 1, 'reps' => '8-10', 'target_weight' => 40, 'tracking_mode' => 'reps', 'library_key' => 'builtin:Barbell_Full_Squat', 'position' => 0]);
    for ($i = 1; $i <= 2; $i++) {
        $entry = $trainee->scheduleEntries()->create(['training_day_id' => $day->id, 'title' => 'Completed', 'entry_type' => 'training', 'scheduled_date' => today()->subDays($i), 'status' => 'completed']);
        $ex = $entry->sessionExercises()->create(['source_training_exercise_id' => $exercise->id, 'source_exercise_key' => $exercise->library_key, 'name' => 'Squat', 'tracking_mode' => 'reps', 'planned_sets' => 1, 'planned_reps_text' => '8-10', 'planned_weight_kg' => 40, 'position' => 0, 'status' => 'completed']);
        $ex->sets()->create(['set_number' => 1, 'actual_reps' => 10, 'actual_weight_kg' => 40, 'completed_at' => now()]);
    }
    $schedule = app(PlanSchedule::class);
    $progression = app(ExerciseProgression::class);
    req();
    $preview = $schedule->preview($plan->fresh(), coach: $coach);
    $suggestion = $progression->forTemplate($trainee, $exercise->fresh(), coach: $coach);
    check($suggestion['can_apply'], 'Progression fixture qualifies');
    foreach (['pause', 'disconnect'] as $state) {
        foreach (['plan', 'schedule', 'progression'] as $operation) {
            $sub->update(['status' => 'active']);
            $relation->update(['status' => 'active']);
            $r = $operation === 'plan' ? req(['name' => 'Forbidden'], UpdateTrainingPlanRequest::class) : req(['signature' => $preview['signature'], 'action' => 'add', 'confirmed' => true]);
            race(function ($db) use ($state, $sub, $relation) {
                $db->table($state === 'pause' ? 'coach_subscriptions' : 'coach_trainee_relationships')->where('id', $state === 'pause' ? $sub->id : $relation->id)->update(['status' => $state === 'pause' ? 'paused' : 'ended']);
            }, function () use ($operation, $r, $plan, $schedule, $progression, $coach, $trainee, $exercise, $suggestion) {
                match ($operation) {
                    'plan' => app(TrainingPlanController::class)->update($r, $plan),'schedule' => app(PlanScheduleController::class)->store($r, $plan, $schedule),'progression' => $progression->applyForCoach($coach, $trainee, $exercise, $suggestion['token'], $suggestion['proposed_value'])
                };
            }, "$state blocks $operation after earlier transaction snapshot");
        }
    }
    $sub->update(['status' => 'active']);
    $relation->update(['status' => 'active']);
    req();
    foreach (['started', 'completed'] as $state) {
        $entry = $trainee->scheduleEntries()->create(['training_day_id' => $day->id, 'title' => 'Future', 'entry_type' => 'training', 'status' => 'planned', 'scheduled_date' => today()->addDay(), 'scheduled_time' => '18:00', 'scheduled_by_user_id' => $coach->id, 'schedule_managed' => true, 'plan_occurrence_key' => 'plan:'.$plan->id.':'.$day->id.':'.today()->addDay()->toDateString()]);
        race(fn ($db) => $db->table('schedule_entries')->where('id', $entry->id)->update($state === 'started' ? ['started_at' => now()] : ['status' => 'completed']), fn () => app(CoachScheduleController::class)->destroy($entry), "Concurrent workout $state protects individual session");
        check($entry->fresh() !== null, 'Protected session retained');
        $entry->delete();
    }
    check($plan->fresh()->name === 'Assigned' && (float) $exercise->fresh()->target_weight === 40.0, 'No plan or target changed by revoked writes');
    File::ensureDirectoryExists(storage_path('app/private/coaching-qa'), 0700);
    file_put_contents(storage_path('app/private/coaching-qa/concurrency.json'), json_encode(['checks' => $results, 'isolation' => $other->selectOne('SELECT @@transaction_isolation AS level')->level], JSON_PRETTY_PRINT));
} finally {
    $coach->delete();
    $trainee->delete();
    DB::disconnect('coach_race');
}
