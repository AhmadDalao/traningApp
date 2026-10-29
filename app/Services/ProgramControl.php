<?php

namespace App\Services;

use App\Models\ProgramRun;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/** Run controls change dates/status only. They never rebuild saved prescriptions. */
class ProgramControl
{
    // Caller holds owner, optional source plan, and run locks in that order.
    public function preview(ProgramRun $run, array $data, ?User $coach = null): array
    {
        $action = $data['action'];
        abort_unless(in_array($run->status, $action === 'resume' ? ['paused'] : ($action === 'end' ? ['active', 'paused'] : ['active']), true), 422, 'This action is not available for the current program status. Refresh the page.');
        $entries = $run->sessions()->withExists('sessionExercises')->lockForUpdate()->get();
        if ($entries->contains(fn ($entry) => $entry->isActiveWorkout())) {
            throw ValidationException::withMessages(['program' => 'Finish or discard the active workout before changing this program schedule.']);
        }
        $position = app(ProgramLifecycle::class)->position($run);
        abort_if($position['all_resolved'], 422, 'This program is already resolved. Refresh to see its completion.');
        $access = app(CoachAccess::class);
        $assignment = $coach ? $access->assignment($coach, $run->user, $run->trainingPlan, true, true) : null;
        $subscription = $assignment ? $access->subscription($assignment->relationship, true) : null;
        $result = ['move' => [], 'keep' => [], 'remove' => [], 'protected' => [], 'days' => 0];
        $eligible = collect();
        foreach ($entries as $entry) {
            $row = [...$entry->only('id', 'title', 'entry_type', 'status', 'program_week', 'program_context', 'scheduled_time'), 'scheduled_date' => $entry->scheduled_date->toDateString()];
            $reason = match (true) {
                $entry->status !== 'planned' => null,
                $entry->started_at !== null || $entry->workout_snapshot !== null || $entry->session_exercises_exists => 'Saved workout details',
                ! $entry->schedule_managed => 'Moved or edited individually',
                $coach && $entry->scheduled_by_user_id !== $coach->id => 'Personal or other-coach session',
                $action === 'end' && ! $access->future($row['scheduled_date'], $entry->scheduled_time) => 'Past unresolved workout',
                default => null,
            };
            if ($entry->status !== 'planned') {
                $result['keep'][] = $row;
            } elseif ($reason) {
                $result['protected'][] = [...$row, 'reason' => $reason];
            } elseif ($action === 'pause' && $row['scheduled_date'] < $data['pause_date']) {
                $result['keep'][] = $row;
            } else {
                $eligible->push($entry);
            }
        }
        $anchor = $eligible->min(fn ($entry) => $entry->scheduled_date->toDateString());
        if ($action === 'pause' && ! empty($data['resume_date'])) {
            $result['days'] = (int) Carbon::parse($data['pause_date'])->diffInDays(Carbon::parse($data['resume_date']));
        } elseif (in_array($action, ['resume', 'shift'], true) && $anchor) {
            // A known return date was already applied at pause; only apply its correction.
            $anchor = $action === 'resume' && $run->resume_on ? $run->resume_on->toDateString() : $anchor;
            $result['days'] = (int) Carbon::parse($anchor)->diffInDays(Carbon::parse($data['start_date']));
        }
        foreach ($eligible as $entry) {
            $row = [...$entry->only('id', 'title', 'entry_type', 'status', 'program_week', 'program_context', 'scheduled_time'), 'scheduled_date' => $entry->scheduled_date->toDateString()];
            if ($action === 'end') {
                $result['remove'][] = $row;
            } else {
                $to = $entry->scheduled_date->copy()->addDays($result['days'])->toDateString();
                if (($result['days'] !== 0 || in_array($action, ['resume', 'shift'], true)) && ($to < today()->toDateString() || ($to === today()->toDateString() && $entry->scheduled_time && ! $access->future($to, $entry->scheduled_time)))) {
                    throw ValidationException::withMessages(['start_date' => 'A workout would remain in the past. Choose a later start date.']);
                }
                if ($coach && ($result['days'] !== 0 || in_array($action, ['resume', 'shift'], true))) {
                    $access->scheduleDate($subscription, $to, $entry->scheduled_time);
                }
                if ($result['days'] !== 0) {
                    $result['move'][] = [...$row, 'to' => $to];
                } else {
                    $result['keep'][] = $row;
                }
            }
        }
        $result['signature'] = hash_hmac('sha256', json_encode([
            $coach?->id, $run->getAttributes(), $data, $entries->map->getAttributes()->all(),
            $assignment?->getAttributes(), $assignment?->relationship->getAttributes(), $subscription?->getAttributes(), $result,
        ], JSON_THROW_ON_ERROR), config('app.key'));

        return $result;
    }

    public function apply(ProgramRun $run, array $data, string $signature, ?User $coach = null): array
    {
        $preview = $this->preview($run, $data, $coach);
        if (! hash_equals($preview['signature'], $signature)) {
            throw ValidationException::withMessages(['program' => 'The program or schedule changed. Review a fresh preview before confirming.']);
        }
        if ($data['action'] === 'end') {
            $position = app(ProgramLifecycle::class)->position($run);
            unset($position['sessions'], $position['next_workout']);
            $run->forceFill(['status' => 'ended', 'ended_at' => now(), 'ended_position' => $position, 'completed_at' => null])->save();
            $run->sessions()->whereIn('id', array_column($preview['remove'], 'id'))->delete();
        } else {
            $from = null;
            $moving = $run->sessions()->whereIn('id', array_column($preview['move'], 'id'))->get()->keyBy('id');
            foreach ($preview['move'] as $row) {
                $entry = $moving[$row['id']];
                $original = substr($entry->plan_occurrence_key, -10);
                $from = $from === null ? $original : min($from, $original);
                $entry->update(['scheduled_date' => $row['to']]);
            }
            if ($from !== null) {
                $run->schedule_shifts = [...($run->schedule_shifts ?? []), ['from' => $from, 'days' => $preview['days']]];
            }
            if ($data['action'] === 'pause') {
                $run->fill(['status' => 'paused', 'paused_from' => $data['pause_date'], 'resume_on' => $data['resume_date'] ?? null]);
            } elseif ($data['action'] === 'resume') {
                $run->fill(['status' => 'active', 'paused_from' => null, 'resume_on' => null]);
            }
            $run->save();
        }

        return $preview;
    }
}
