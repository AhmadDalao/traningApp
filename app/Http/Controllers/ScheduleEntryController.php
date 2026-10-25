<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreScheduleEntryRequest;
use App\Http\Requests\UpdateScheduleEntryRequest;
use App\Http\Requests\UpdateScheduleEntryStatusRequest;
use App\Models\ScheduleEntry;
use App\Models\TrainingDay;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class ScheduleEntryController extends Controller
{
    public function calendar(Request $request)
    {
        $request->validate(['date' => 'nullable|date_format:Y-m-d', 'view' => 'nullable|in:dayGridMonth,dayGridWeek,listMonth']);
        $date = Carbon::parse($request->input('date', now()->toDateString()));
        $start = $date->copy()->startOfMonth()->subWeek();
        $end = $date->copy()->endOfMonth()->addWeek();

        return Inertia::render('Calendar/Index', [
            'entries' => $request->user()->scheduleEntries()->whereBetween('scheduled_date', [$start->toDateString(), $end->toDateString()])->orderBy('scheduled_date')->get(),
            'calendarDate' => $date->toDateString(), 'calendarView' => $request->input('view', 'dayGridMonth'),
            'plans' => $this->plans($request),
        ]);
    }

    private function plans(Request $request)
    {
        return $request->user()->trainingPlans()->with('trainingDays')->orderBy('name')->get();
    }

    public function create(Request $request)
    {
        return Inertia::render('ScheduleEntries/Form', ['plans' => $this->plans($request), 'selectedDay' => $request->integer('day'), 'selectedDate' => $request->input('date', now()->toDateString())]);
    }

    public function store(StoreScheduleEntryRequest $request)
    {
        $entry = $request->user()->scheduleEntries()->create([...$this->entryData($request), 'status' => 'planned']);

        return to_route('schedule-entries.show', $entry)->with('success', 'Session added to your calendar.');
    }

    private function entryData(StoreScheduleEntryRequest $request, ?ScheduleEntry $entry = null): array
    {
        $data = $request->validated();
        $day = ! empty($data['training_day_id']) ? TrainingDay::whereHas('trainingPlan', fn ($q) => $q->where('user_id', $request->user()->id))->findOrFail($data['training_day_id']) : null;

        return [
            'training_day_id' => $day?->id,
            'title' => $data['title'] ?? $day?->title ?? $entry?->title ?? 'Rest Day',
            'entry_type' => $day?->type ?? $data['entry_type'],
            'focus_area' => $day ? $day->focus_area : ($data['entry_type'] === 'training' ? $entry?->focus_area : null),
            'scheduled_date' => $data['scheduled_date'], 'notes' => $data['notes'] ?? null,
        ];
    }

    public function show(ScheduleEntry $scheduleEntry)
    {
        Gate::authorize('view', $scheduleEntry);

        return Inertia::render('ScheduleEntries/Show', ['entry' => $scheduleEntry->load('trainingDay.exercises', 'trainingDay.trainingPlan')]);
    }

    public function edit(Request $request, ScheduleEntry $scheduleEntry)
    {
        Gate::authorize('update', $scheduleEntry);

        return Inertia::render('ScheduleEntries/Form', ['entry' => $scheduleEntry, 'plans' => $this->plans($request)]);
    }

    public function update(UpdateScheduleEntryRequest $request, ScheduleEntry $scheduleEntry)
    {
        $scheduleEntry->update($this->entryData($request, $scheduleEntry));

        return to_route('schedule-entries.show', $scheduleEntry)->with('success', 'Session updated.');
    }

    public function status(UpdateScheduleEntryStatusRequest $request, ScheduleEntry $scheduleEntry)
    {
        $scheduleEntry->update($request->validated());

        return back()->with('success', 'Session marked '.$scheduleEntry->status.'.');
    }

    public function destroy(ScheduleEntry $scheduleEntry)
    {
        Gate::authorize('delete', $scheduleEntry);
        $scheduleEntry->delete();

        return to_route('calendar')->with('success', 'Session deleted.');
    }
}
