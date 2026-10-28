<?php

use App\Http\Controllers\ScheduleChangeController;
use App\Models\ScheduleEntry;
use App\Models\User;
use App\Services\PlanSchedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'training_planner_test') {
    throw new RuntimeException('Isolated test database required');
}
config(['database.connections.calendar_race' => config('database.connections.mysql')]);
$other = DB::connection('calendar_race');
$user = User::create(['name' => 'Calendar race', 'email' => 'v17-race-'.bin2hex(random_bytes(5)).'@example.test', 'password' => bin2hex(random_bytes(24))]);
$checks = [];
function calendarRequest(array $data): Request
{
    global $user;
    $request = Request::create('/reschedule', 'POST', $data);
    $request->setUserResolver(fn () => $user);
    app()->instance('request', $request);
    auth()->setUser($user);

    return $request;
}
function calendarCheck(bool $valid, string $name): void
{
    global $checks;
    if (! $valid) {
        throw new RuntimeException($name);
    }
    $checks[] = $name;
    echo 'PASS: '.$name.PHP_EOL;
}
try {
    $plan = $user->trainingPlans()->create(['name' => 'Ongoing', 'schedule_mode' => 'ongoing', 'start_date' => today()->addDay()]);
    $plan->forceFill(['schedule_enabled' => true, 'schedule_actor_id' => $user->id])->save();
    $day = $plan->trainingDays()->create(['title' => 'Daily', 'type' => 'rest', 'weekdays' => [0, 1, 2, 3, 4, 5, 6], 'default_time' => '18:00']);
    $service = app(PlanSchedule::class);
    $service->ensureWindow($user);
    $entry = $user->scheduleEntries()->orderBy('id')->firstOrFail();
    $date = today()->addDays(2)->toDateString();
    $data = ['scheduled_date' => $date, 'scheduled_time' => '18:00', 'scope' => 'one'];
    $controller = app(ScheduleChangeController::class);
    $preview = $controller->preview(calendarRequest($data), $entry)->getData(true)['preview'];
    DB::beginTransaction();
    DB::table('users')->count(); // Establish an older REPEATABLE READ snapshot.
    $other->table('schedule_entries')->where('id', $entry->id)->update(['started_at' => now()]);
    $denied = false;
    try {
        $controller->store(calendarRequest([...$data, 'confirmed' => true, 'signature' => $preview['signature']]), $entry);
    } catch (HttpExceptionInterface|ValidationException $error) {
        $denied = true;
    }
    DB::rollBack();
    calendarCheck($denied && $entry->fresh()->scheduled_date->toDateString() !== $date, 'Move rechecks a concurrently started workout');
    $entry->update(['started_at' => null]);
    $stopData = ['end_date' => today()->addDays(3)->toDateString()];
    $preview = $controller->stopPreview(calendarRequest($stopData), $plan)->getData(true)['preview'];
    $later = $user->scheduleEntries()->whereDate('scheduled_date', today()->addDays(8))->firstOrFail();
    DB::beginTransaction();
    DB::table('users')->count();
    $other->table('schedule_entries')->where('id', $later->id)->update(['status' => 'completed']);
    $denied = false;
    try {
        $controller->stopStore(calendarRequest([...$stopData, 'confirmed' => true, 'signature' => $preview['signature']]), $plan);
    } catch (HttpExceptionInterface|ValidationException $error) {
        $denied = true;
    }
    DB::rollBack();
    calendarCheck($denied && $later->fresh()->status === 'completed' && $plan->fresh()->end_date === null, 'Stop rejects stale diff and preserves concurrently completed session');
    $cancel = $user->scheduleEntries()->whereDate('scheduled_date', today()->addDays(9))->firstOrFail();
    DB::beginTransaction();
    DB::table('users')->count();
    $other->transaction(function () use ($other, $cancel, $plan) {
        $other->table('schedule_occurrence_cancellations')->insert(['training_plan_id' => $plan->id, 'occurrence_key' => $cancel->plan_occurrence_key, 'created_at' => now()]);
        $other->table('schedule_entries')->where('id', $cancel->id)->delete();
    });
    $service->ensureWindow($user);
    // A locking read must see a cancellation committed after the earlier snapshot.
    $recreated = ScheduleEntry::where('plan_occurrence_key', $cancel->plan_occurrence_key)->lockForUpdate()->exists();
    DB::rollBack();
    calendarCheck(! $recreated, 'Rolling generation sees concurrent cancellation instead of recreating it');
    $count = $user->scheduleEntries()->count();
    $service->ensureWindow($user);
    $service->ensureWindow($user);
    calendarCheck($count === $user->scheduleEntries()->count(), 'Repeated rolling refresh is idempotent after cancellation');
    echo count($checks).' calendar concurrency checks passed.'.PHP_EOL;
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $user->delete();
}
