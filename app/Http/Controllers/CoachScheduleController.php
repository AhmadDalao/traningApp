<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreScheduleEntryRequest;
use App\Models\ScheduleEntry;
use App\Models\TrainingDay;
use App\Services\CoachAccess;
use App\Services\Notifications\CoachNotifications;
use App\Services\PlanSchedule;
use App\Services\TrainingContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class CoachScheduleController extends Controller
{
    public function __construct(private TrainingContext $context, private CoachAccess $access) {}

    public function create(Request $request)
    {
        $request->validate(['date' => 'nullable|date_format:Y-m-d']);

        return Inertia::render('ScheduleEntries/Form', ['sameDayEntries' => $this->sameDay($request->input('date', today()->toDateString())), 'plans' => $this->context->schedulingPlans(), 'selectedDay' => $request->integer('day'), 'selectedDate' => $request->input('date', today()->toDateString())]);
    }

    private function sameDay(string $date)
    {
        return $this->context->sessions()->whereDate('scheduled_date', $date)->withThumbnail()->orderBy('scheduled_time')->get()->map(fn ($entry) => [
            ...$entry->only('id', 'title', 'scheduled_time', 'status', 'thumbnail_url'), 'scheduled_date' => $entry->scheduled_date->toDateString(),
        ]);
    }

    public function edit(ScheduleEntry $scheduleEntry)
    {
        $this->access->futureSession($this->context->actor(), $this->context->owner(), $scheduleEntry);

        return Inertia::render('ScheduleEntries/Form', ['entry' => $scheduleEntry, 'plans' => $this->context->schedulingPlans()]);
    }

    private function save(StoreScheduleEntryRequest $request, ?ScheduleEntry $entry = null): ScheduleEntry
    {
        $request->validate(array_fill_keys(['status', 'started_at', 'completed_at', 'workout_snapshot', 'duration_minutes', 'calories_burned', 'estimated_calories', 'details_only', 'actual_weights', 'scheduled_by_user_id', 'user_id', 'plan_occurrence_key', 'schedule_managed'], 'prohibited'));

        return $this->context->write(function ($relationship, $subscription) use ($request, $entry) {
            $data = $request->validated();
            $day = TrainingDay::whereIn('training_plan_id', $this->context->plans(true)->select('training_plans.id'))->findOrFail($data['training_day_id']);
            // Use the same plan/day order as template editors and bulk scheduling.
            $plan = $day->trainingPlan()->lockForUpdate()->firstOrFail();
            $day = TrainingDay::lockForUpdate()->findOrFail($day->id);
            $assignment = $this->access->assignment($request->user(), $this->context->owner(), $plan, true, true);
            $subscription = $this->access->subscriptionFor($assignment, true);
            $this->access->scheduleDate($subscription, $data['scheduled_date'], $data['scheduled_time'] ?? null);
            if ($entry) {
                $entry = ScheduleEntry::lockForUpdate()->findOrFail($entry->id);
                $entry->unsetRelation('trainingDay');
                $this->access->futureSession($request->user(), $this->context->owner(), $entry);
            }
            $fields = ['training_day_id' => $day->id, 'title' => $data['title'] ?? $day->title, 'entry_type' => $day->type, 'focus_area' => $day->focus_area, 'icon' => $day->icon, 'scheduled_date' => $data['scheduled_date'], 'scheduled_time' => $data['scheduled_time'] ?? null, 'notes' => $data['notes'] ?? null];
            if ($entry) {
                if ($entry->program_run_id) {
                    abort_unless($entry->training_day_id === $day->id, 422, 'A program session must keep its original training day.');
                    $fields = collect($fields)->only('title', 'scheduled_date', 'scheduled_time', 'notes')->all();
                }
                $entry->fill($fields);
                if ($entry->isDirty() && $entry->plan_occurrence_key) {
                    $entry->schedule_managed = false;
                }
                $entry->save();
                if ($entry->wasChanged(['scheduled_date', 'scheduled_time'])) {
                    app(CoachNotifications::class)->plan($request->user(), $plan, 'schedule', (string) Str::uuid());
                }

                return $entry;
            }

            $created = $this->context->owner()->scheduleEntries()->create([...$fields, 'status' => 'planned', 'scheduled_by_user_id' => $request->user()->id]);
            app(CoachNotifications::class)->plan($request->user(), $plan, 'schedule', 'created:'.$created->id);

            return $created;
        });
    }

    public function store(StoreScheduleEntryRequest $request)
    {
        $entry = $this->save($request);

        return $this->context->redirect('/schedule-entries/'.$entry->id)->with('success', 'Session added to the trainee’s Calendar.');
    }

    public function update(StoreScheduleEntryRequest $request, ScheduleEntry $scheduleEntry)
    {
        $entry = $this->save($request, $scheduleEntry);

        return $this->context->redirect('/schedule-entries/'.$entry->id)->with('success', 'Future session updated.');
    }

    public function destroy(ScheduleEntry $scheduleEntry)
    {
        $this->context->write(function () use ($scheduleEntry) {
            $entry = ScheduleEntry::lockForUpdate()->findOrFail($scheduleEntry->id);
            $this->access->futureSession($this->context->actor(), $this->context->owner(), $entry);
            abort_if($entry->program_run_id, 422, 'Use the program change preview to remove future program sessions.');
            app(PlanSchedule::class)->rememberCancellation($entry);
            app(CoachNotifications::class)->plan($this->context->actor(), $entry->trainingDay->trainingPlan, 'schedule', 'deleted:'.$entry->id);
            $entry->delete();
        });

        return $this->context->redirect('/calendar')->with('success', 'Future coaching session removed.');
    }
}
