<?php

namespace App\Services;

use App\Models\ProgramRun;
use App\Models\ScheduleEntry;
use App\Models\TrainingPlan;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/** Finite schedules share Calendar entries, occurrence keys and protection rules with simple plans. */
class ProgramSchedule
{
    public function desired(TrainingPlan $plan, string $start, string $identity): array
    {
        abort_unless($plan->isProgram(), 422, 'Choose a structured program.');
        $definition = app(ProgramDefinition::class)->snapshot($plan);
        app(ProgramDefinition::class)->validate($definition['blocks'], $definition['duration_weeks']);
        $sessions = [];
        foreach ($definition['blocks'] as $block) {
            $training = collect($block['days'])->filter(fn ($day) => $day['type'] === 'training' && count($day['weekdays'] ?? []) && count($day['exercises']));
            if ($training->isEmpty()) {
                throw ValidationException::withMessages(['program' => 'Add a training day with exercises and weekdays to every block before starting.']);
            }
            foreach ($block['days'] as $day) {
                if ($day['type'] === 'training' && (! count($day['weekdays'] ?? []) || ! count($day['exercises']))) {
                    throw ValidationException::withMessages(['program' => 'Every training day needs exercises and at least one weekday.']);
                }
            }
            for ($week = $block['start_week']; $week <= $block['end_week']; $week++) {
                // Week 1 is the seven-day interval beginning on the chosen start date.
                for ($offset = 0; $offset < 7; $offset++) {
                    $date = Carbon::parse($start)->addDays(($week - 1) * 7 + $offset);
                    foreach ($block['days'] as $day) {
                        if (! in_array($date->dayOfWeek, $day['weekdays'] ?? [], false)) {
                            continue;
                        }
                        $context = ['name' => $definition['name'], 'block_id' => $block['id'], 'block_name' => $block['name'], 'block_type' => $block['type'],
                            'start_week' => $block['start_week'], 'end_week' => $block['end_week'], 'duration_weeks' => $definition['duration_weeks'],
                            'definition_hash' => hash('sha256', json_encode($day, JSON_THROW_ON_ERROR))];
                        $sessions[] = ['key' => 'program:'.$identity.':'.$week.':'.$day['id'].':'.$date->toDateString(),
                            'training_day_id' => $day['id'], 'program_week' => $week, 'program_context' => $context,
                            'title' => $day['title'], 'entry_type' => $day['type'], 'focus_area' => $day['focus_area'], 'icon' => $day['icon'],
                            'scheduled_date' => $date->toDateString(), 'scheduled_time' => $day['default_time'], 'notes' => $day['notes'],
                            'program_prescription' => ['version' => 1, 'title' => $day['title'], 'focus_area' => $day['focus_area'], 'icon' => $day['icon'],
                                'training_day_title' => $day['title'], 'training_plan_id' => $plan->id, 'training_plan_name' => $plan->name, 'template_available' => true,
                                'exercises' => $day['type'] === 'training' ? $day['exercises'] : []],
                        ];
                        if (count($sessions) > 1000) {
                            throw ValidationException::withMessages(['program' => 'Keep this program below 1,000 scheduled sessions. Reduce its duration or weekdays.']);
                        }
                    }
                }
            }
        }

        return ['definition' => $definition, 'sessions' => $sessions];
    }

    public function preview(TrainingPlan $plan, string $start, string $nonce, ?User $coach = null): array
    {
        $desired = $this->desired($plan, $start, 'preview');
        $subscription = $coach ? app(CoachAccess::class)->subscription(app(CoachAccess::class)->relationship($coach, $plan->user)) : null;
        if ($coach) {
            app(CoachAccess::class)->assignment($coach, $plan->user, $plan, true);
            foreach ($desired['sessions'] as $session) {
                app(CoachAccess::class)->scheduleDate($subscription, $session['scheduled_date'], $session['scheduled_time']);
            }
        }
        $signature = $this->sign([$plan->id, $plan->user_id, $coach?->id, $start, $nonce, $desired, $subscription?->getAttributes()]);

        return ['signature' => $signature, 'sessions' => $this->publicRows($desired['sessions']), 'count' => count($desired['sessions']),
            'start_date' => $start, 'ends_on' => Carbon::parse($start)->addDays($plan->duration_weeks * 7 - 1)->toDateString()];
    }

    public function start(TrainingPlan $plan, array $data, ?User $coach, array &$paths): ProgramRun
    {
        // Controller holds user -> plan locks, including the coach relationship lock when needed.
        $token = $this->sign([$plan->user_id, $plan->id, $coach?->id, $data['nonce']]);
        if ($existing = ProgramRun::where('start_token', $token)->lockForUpdate()->first()) {
            return $existing;
        }
        $preview = $this->preview($plan, $data['start_date'], $data['nonce'], $coach);
        $this->check($data['signature'], $preview['signature']);
        $run = ProgramRun::create(['user_id' => $plan->user_id, 'training_plan_id' => $plan->id, 'started_by_user_id' => $coach?->id ?? $plan->user_id,
            'start_token' => $token, 'started_on' => $data['start_date'], 'definition_snapshot' => app(ProgramDefinition::class)->snapshot($plan)]);
        $desired = $this->desired($plan, $data['start_date'], (string) $run->id);
        $media = [];
        foreach ($desired['sessions'] as $session) {
            $this->createEntry($run, $session, $coach, $media, $paths);
        }

        return $run;
    }

    public function changes(ProgramRun $run, TrainingPlan $plan, ?User $coach = null): array
    {
        abort_unless($run->status === 'active', 422, 'Only an active program run can be updated.');
        $desired = $this->desired($plan, $run->started_on->toDateString(), (string) $run->id);
        $entries = $run->sessions()->withExists('sessionExercises')->lockForUpdate()->get();
        $byKey = $entries->keyBy('plan_occurrence_key');
        $currentWeek = $coach ? app(ProgramLifecycle::class)->position($run)['current_week'] : null;
        $result = ['add' => [], 'update' => [], 'remove' => [], 'keep' => [], 'protected' => []];
        $keys = [];
        foreach ($desired['sessions'] as $row) {
            $keys[] = $row['key'];
            $entry = $byKey->get($row['key']);
            if ($entry) {
                if ($reason = $this->protection($entry, $coach, $currentWeek)) {
                    $result['protected'][] = [...$row, 'id' => $entry->id, 'reason' => $reason];
                } elseif ($entry->program_context != $row['program_context'] || substr($entry->scheduled_time ?? '', 0, 5) !== substr($row['scheduled_time'] ?? '', 0, 5)) {
                    $result['update'][] = [...$row, 'id' => $entry->id];
                } else {
                    $result['keep'][] = [...$row, 'id' => $entry->id];
                }
            } elseif (app(CoachAccess::class)->future($row['scheduled_date'], $row['scheduled_time'])) {
                if ($coach) {
                    app(CoachAccess::class)->scheduleDate(app(CoachAccess::class)->subscription(app(CoachAccess::class)->relationship($coach, $plan->user)), $row['scheduled_date'], $row['scheduled_time']);
                }
                $result['add'][] = $row;
            }
        }
        foreach ($entries as $entry) {
            if (! in_array($entry->plan_occurrence_key, $keys, true)) {
                $reason = $this->protection($entry, $coach, $currentWeek);
                $result[$reason ? 'protected' : 'remove'][] = [...$entry->only('id', 'title', 'entry_type', 'program_week', 'scheduled_date', 'scheduled_time', 'program_context'), 'reason' => $reason];
            }
        }
        $result['signature'] = $this->sign([$coach?->id, $run->getAttributes(), $desired, $entries->map->getAttributes()->all(), $result]);
        $result['definition'] = $desired['definition'];

        return $result;
    }

    public function applyChanges(ProgramRun $run, TrainingPlan $plan, string $signature, ?User $coach, array &$paths): void
    {
        $changes = $this->changes($run, $plan, $coach);
        $this->check($signature, $changes['signature']);
        $media = [];
        foreach ($changes['update'] as $row) {
            $entry = ScheduleEntry::findOrFail($row['id']);
            $entry->fill(collect($row)->except('key', 'id')->all());
            $entry->program_prescription = $this->prescription($row, $media, $paths);
            $entry->save();
        }
        foreach ($changes['add'] as $row) {
            $this->createEntry($run, $row, $coach, $media, $paths);
        }
        $run->sessions()->whereIn('id', array_column($changes['remove'], 'id'))->delete();
        // Retain original metadata for blocks containing protected history.
        $protectedIds = collect($changes['protected'])->pluck('program_context.block_id')->filter()->unique();
        $old = collect($run->definition_snapshot['blocks'])->keyBy('id');
        $definition = $changes['definition'];
        $definition['blocks'] = collect($definition['blocks'])->map(fn ($block) => $protectedIds->contains($block['id']) ? ($old[$block['id']] ?? $block) : $block)->all();
        foreach ($old as $id => $block) {
            if ($protectedIds->contains($id) && ! collect($definition['blocks'])->contains('id', $id)) {
                $definition['blocks'][] = $block;
            }
        }
        $definition['duration_weeks'] = max($definition['duration_weeks'], $run->sessions()->max('program_week') ?? 1);
        $run->update(['definition_snapshot' => $definition]);
    }

    private function protection(ScheduleEntry $entry, ?User $coach, ?int $currentWeek): ?string
    {
        if ($entry->status !== 'planned' || $entry->started_at || $entry->workout_snapshot !== null || $entry->session_exercises_exists) {
            return 'Completed, skipped, active or saved workout';
        }
        if (! app(CoachAccess::class)->future($entry->scheduled_date->toDateString(), $entry->scheduled_time)) {
            return 'Past session';
        }
        if (! $entry->schedule_managed) {
            return 'Moved or edited individually';
        }
        if ($coach && ($entry->program_context['start_week'] ?? 1) <= ($currentWeek ?? 52)) {
            return 'Current or resolved program block';
        }
        if ($coach && ! app(CoachAccess::class)->canManageSession($coach, $entry->user, $entry)) {
            return 'Outside this coach’s management permissions';
        }

        return null;
    }

    private function createEntry(ProgramRun $run, array $row, ?User $coach, array &$media, array &$paths): void
    {
        $entry = $run->user->scheduleEntries()->make([...collect($row)->except('key')->all(),
            'program_run_id' => $run->id, 'plan_occurrence_key' => $row['key'], 'status' => 'planned', 'schedule_managed' => true,
            'scheduled_by_user_id' => $coach?->id ?? $run->user_id]);
        $entry->program_prescription = $this->prescription($row, $media, $paths);
        $entry->save();
    }

    private function prescription(array $row, array &$media, array &$paths): array
    {
        $key = $row['program_context']['definition_hash'];
        if (! isset($media[$key])) {
            $snapshot = $row['program_prescription'];
            foreach ($snapshot['exercises'] as &$exercise) {
                foreach (['thumbnail_path', 'custom_icon_path'] as $field) {
                    $exercise[$field] = app(ExerciseLibrary::class)->copyMedia($exercise, $field, 'program-media', $paths);
                }
            }
            $media[$key] = $snapshot;
        }

        return $media[$key];
    }

    public function publicRows(array $rows): array
    {
        return array_map(fn ($row) => [...collect($row)->except('program_prescription')->all(), 'scheduled_date' => $row['scheduled_date'] instanceof CarbonInterface ? $row['scheduled_date']->toDateString() : $row['scheduled_date']], $rows);
    }

    private function sign(array $value): string
    {
        return hash_hmac('sha256', json_encode($value, JSON_THROW_ON_ERROR), config('app.key'));
    }

    private function check(string $received, string $expected): void
    {
        if (! hash_equals($expected, $received)) {
            throw ValidationException::withMessages(['program' => 'The program or schedule changed. Review a fresh preview before confirming.']);
        }
    }
}
