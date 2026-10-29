<?php

namespace App\Http\Controllers;

use App\Models\ScheduleEntry;
use App\Models\TrainingPlan;
use App\Models\User;
use App\Services\CoachAccess;
use App\Services\PlanSchedule;
use App\Services\TrainingContext;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ScheduleChangeController extends Controller
{
    public function __construct(private TrainingContext $context, private PlanSchedule $schedule) {}

    public function preview(Request $request, ScheduleEntry $scheduleEntry)
    {
        return $this->move($request, $scheduleEntry, false);
    }

    public function store(Request $request, ScheduleEntry $scheduleEntry)
    {
        return $this->move($request, $scheduleEntry, true);
    }

    private function move(Request $request, ScheduleEntry $entry, bool $apply)
    {
        $data = $request->validate([
            'scheduled_date' => 'required|date_format:Y-m-d', 'scheduled_time' => 'nullable|date_format:H:i',
            'scope' => 'required|in:one,future', 'signature' => ($apply ? 'required' : 'nullable').'|string|size:64',
            'confirmed' => $apply ? 'required|accepted' : 'sometimes|boolean',
        ]);
        abort_unless($entry->user_id === $this->context->owner()->id, 403);

        return $this->context->write(function () use ($entry, $data, $apply) {
            User::whereKey($this->context->owner()->id)->lockForUpdate()->firstOrFail();
            $plan = $entry->trainingDay?->trainingPlan;
            if ($plan) {
                $plan = TrainingPlan::lockForUpdate()->findOrFail($plan->id);
                $plan->setRelation('trainingDays', $plan->trainingDays()->lockForUpdate()->get());
            }
            $run = $entry->lockRunForMutation();
            $entry = ScheduleEntry::lockForUpdate()->findOrFail($entry->id);
            if ($run) {
                $entry->setRelation('programRun', $run);
            }
            if ($plan) {
                $lockedDay = $plan->trainingDays->firstWhere('id', $entry->training_day_id);
                abort_unless($lockedDay, 409, 'The session’s training day changed. Refresh and try again.');
                $lockedDay->setRelation('trainingPlan', $plan);
                $entry->setRelation('trainingDay', $lockedDay);
            } else {
                abort_if($entry->training_day_id !== null, 409, 'The session changed. Refresh and try again.');
            }
            $coach = $this->context->coaching() ? $this->context->actor() : null;
            abort_unless($this->schedule->canMove($entry, $this->context->owner(), $coach), 403, 'Only future planned, unstarted sessions without saved workout data can move.');
            $time = $data['scheduled_time'] ?? null;
            if (! app(CoachAccess::class)->future($data['scheduled_date'], $time)) {
                throw ValidationException::withMessages(['scheduled_date' => 'Choose a future date, or a time later today.']);
            }
            if ($entry->scheduled_date->toDateString() === $data['scheduled_date'] && ($entry->scheduled_time === null ? null : substr($entry->scheduled_time, 0, 5)) === $time) {
                throw ValidationException::withMessages(['scheduled_date' => 'Choose a different date or time.']);
            }
            if ($coach) {
                app(CoachAccess::class)->scheduleDate(app(CoachAccess::class)->subscription(app(CoachAccess::class)->relationship($coach, $this->context->owner())), $data['scheduled_date'], $time);
            }
            $before = $entry->getAttributes();
            $preview = ['add' => [], 'keep' => [], 'remove' => [], 'protected' => []];
            $day = null;
            if ($data['scope'] === 'future') {
                abort_unless($this->schedule->canMoveSeries($entry), 403, 'This session is independent. Move this session only, or use Manage Schedule.');
                $this->context->plan($plan, 'update');
                if ($data['scheduled_date'] < $plan->start_date->toDateString() || ($plan->end_date && $data['scheduled_date'] > $plan->end_date->toDateString())) {
                    throw ValidationException::withMessages(['scheduled_date' => 'Keep the recurring change within this plan’s period.']);
                }
                if ($time !== ($entry->scheduled_time === null ? null : substr($entry->scheduled_time, 0, 5))) {
                    throw ValidationException::withMessages(['scheduled_time' => 'This and future changes weekdays. Use the training day editor to change the usual time.']);
                }
                $before = [$before, $this->schedule->preview($plan, lock: true, coach: $coach)['signature']];
                $day = $plan->trainingDays->firstWhere('id', $entry->training_day_id);
                $from = $entry->scheduled_date->toDateString();
                $this->schedule->moveWeekday($day, $from, $data['scheduled_date']);
                $preview = $this->schedule->preview($plan, lock: true, coach: $coach, change: ['entry' => $entry->id, 'date' => $data['scheduled_date'], 'time' => $time, 'day' => $day->id, 'from' => $from]);
            }
            $preview['destination_count'] = $this->context->owner()->scheduleEntries()->whereKeyNot($entry->id)->whereDate('scheduled_date', $data['scheduled_date'])->count();
            $preview['move'] = ['id' => $entry->id, 'title' => $entry->title, 'from' => $entry->scheduled_date->toDateString(), 'to' => $data['scheduled_date'], 'time' => $time];
            $preview['signature'] = hash_hmac('sha256', json_encode([$this->context->actor()->id, $before, $data['scope'], $preview], JSON_THROW_ON_ERROR), config('app.key'));
            if ($apply) {
                $this->checkSignature($data['signature'], $preview['signature']);
                $entry->forceFill(['scheduled_date' => $data['scheduled_date'], 'scheduled_time' => $time, 'schedule_managed' => false])->save();
                if ($day) {
                    ScheduleEntry::where('user_id', $this->context->owner()->id)->whereIn('id', array_column($preview['remove'], 'id'))->delete();
                    foreach ($preview['add'] as $session) {
                        $this->context->owner()->scheduleEntries()->firstOrCreate(['plan_occurrence_key' => $session['key']], [
                            ...collect($session)->except('key', 'existing', 'existing_date', 'replaces_id')->all(),
                            'status' => 'planned', 'schedule_managed' => true, 'scheduled_by_user_id' => $this->context->actor()->id,
                        ]);
                    }
                    $day->save();
                }
            }

            return response()->json(['preview' => $preview, 'saved' => $apply]);
        });
    }

    public function stopPreview(Request $request, TrainingPlan $trainingPlan)
    {
        return $this->stop($request, $trainingPlan, false);
    }

    public function stopStore(Request $request, TrainingPlan $trainingPlan)
    {
        return $this->stop($request, $trainingPlan, true);
    }

    private function stop(Request $request, TrainingPlan $plan, bool $apply)
    {
        $this->context->plan($plan, 'update');
        abort_if($this->context->templates(), 403);
        $data = $request->validate(['end_date' => 'required|date_format:Y-m-d|after_or_equal:today', 'signature' => ($apply ? 'required' : 'nullable').'|string|size:64', 'confirmed' => $apply ? 'required|accepted' : 'sometimes|boolean']);

        return $this->context->write(function () use ($plan, $data, $apply) {
            User::whereKey($this->context->owner()->id)->lockForUpdate()->firstOrFail();
            $plan = TrainingPlan::lockForUpdate()->findOrFail($plan->id);
            $this->context->plan($plan, 'update');
            abort_unless($plan->schedule_mode === 'ongoing', 422, 'This schedule is no longer ongoing. Refresh the page.');
            $plan->setRelation('trainingDays', $plan->trainingDays()->lockForUpdate()->get());
            $preview = $this->schedule->preview($plan, lock: true, coach: $this->context->coaching() ? $this->context->actor() : null);
            $signature = $preview['signature'];
            $preview['add'] = [];
            $preview['keep'] = array_values(array_filter($preview['eligible'], fn ($row) => $row['scheduled_date'] <= $data['end_date']));
            $preview['remove'] = array_values(array_filter($preview['eligible'], fn ($row) => $row['scheduled_date'] > $data['end_date']));
            $preview['signature'] = hash_hmac('sha256', $signature.$data['end_date'], config('app.key'));
            if ($apply) {
                $this->checkSignature($data['signature'], $preview['signature']);
                ScheduleEntry::where('user_id', $plan->user_id)->whereIn('id', array_column($preview['remove'], 'id'))->delete();
                // Keep rolling until the chosen stop date, even when it is beyond today's horizon.
                $plan->forceFill(['end_date' => $data['end_date']])->save();
            }

            return response()->json(['preview' => $preview, 'saved' => $apply]);
        });
    }

    private function checkSignature(string $received, string $expected): void
    {
        if (! hash_equals($expected, $received)) {
            throw ValidationException::withMessages(['schedule' => 'The schedule changed. Review a fresh preview before applying.']);
        }
    }
}
