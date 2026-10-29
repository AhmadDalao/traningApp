<?php

namespace App\Services;

use App\Models\TrainingBlock;
use App\Models\TrainingDay;
use App\Models\TrainingPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProgramDefinition
{
    public function validate(array $blocks, int $duration): void
    {
        Validator::make(['blocks' => $blocks, 'duration_weeks' => $duration], [
            'duration_weeks' => 'required|integer|between:1,52', 'blocks' => 'required|array|min:1|max:52',
            'blocks.*.name' => 'required|string|max:150', 'blocks.*.start_week' => 'required|integer|between:1,52',
            'blocks.*.end_week' => 'required|integer|between:1,52', 'blocks.*.type' => 'required|in:normal,deload',
            'blocks.*.description' => 'nullable|string|max:5000', 'blocks.*.id' => 'nullable|integer|distinct',
            'blocks.*.copy_from' => 'nullable|integer',
        ])->validate();
        $next = 1;
        foreach ($blocks as $block) {
            if ((int) $block['start_week'] !== $next || $block['end_week'] < $block['start_week']) {
                throw ValidationException::withMessages(['blocks' => 'Blocks must cover every week in order, with no gaps or overlaps.']);
            }
            $next = (int) $block['end_week'] + 1;
        }
        if ($next !== $duration + 1) {
            throw ValidationException::withMessages(['blocks' => 'The last block must end at the program duration. Adjust the ranges before saving.']);
        }
    }

    public function snapshot(TrainingPlan $plan): array
    {
        $plan->setRelation('blocks', $plan->blocks()->with([
            'trainingDays' => fn ($query) => $query->lockForUpdate(),
            'trainingDays.exercises' => fn ($query) => $query->lockForUpdate(),
        ])->lockForUpdate()->get());

        return ['version' => 1, 'name' => $plan->name, 'description' => $plan->description, 'duration_weeks' => $plan->duration_weeks,
            'blocks' => $plan->blocks->map(fn ($block) => [...$block->only('id', 'name', 'start_week', 'end_week', 'position', 'type', 'description'),
                'days' => $block->trainingDays->map(fn ($day) => [...$day->only('id', 'title', 'type', 'focus_area', 'icon', 'notes', 'weekdays', 'default_time', 'position'),
                    'exercises' => $day->exercises->map(fn ($exercise) => [...$exercise->only('id', 'name', 'sets', 'reps', 'target_weight', 'tracking_mode', 'superset_group', 'notes', 'position', 'icon', 'thumbnail_path', 'custom_icon_path'), 'source_exercise_key' => $exercise->library_key])->all(),
                ])->all(),
            ])->all()];
    }

    public function coachCanEditBlock(TrainingBlock $block): bool
    {
        foreach ($block->trainingPlan->programRuns()->where('status', '!=', 'ended')->get() as $run) {
            $position = app(ProgramLifecycle::class)->position($run);
            if ($position['current_week'] === null || $block->start_week <= $position['current_week']) {
                return false;
            }
            $training = collect($position['sessions'])->where('entry_type', 'training')->where('program_context.block_id', $block->id);
            if ($training->isNotEmpty() && $training->every(fn ($entry) => in_array($entry['status'], ['completed', 'skipped'], true))) {
                return false;
            }
        }

        return true;
    }

    public function dayWritable(TrainingDay $day, bool $coach): void
    {
        if ($coach && $day->training_block_id) {
            abort_unless($this->coachCanEditBlock($day->trainingBlock), 403, 'Only future program blocks can be edited by the coach.');
        }
    }

    /** Atomic structure editing: never persist a half-covered program. */
    public function saveBlocks(TrainingPlan $plan, array $rows, int $duration, bool $coach, array &$paths): void
    {
        $this->validate($rows, $duration);
        $existing = $plan->blocks()->lockForUpdate()->get()->keyBy('id');
        $kept = [];
        foreach ($rows as $position => $row) {
            $block = ! empty($row['id']) ? $existing->get((int) $row['id']) : null;
            abort_if(! empty($row['id']) && ! $block, 404);
            $source = ! empty($row['copy_from']) ? $existing->get((int) $row['copy_from']) : null;
            abort_if(! empty($row['copy_from']) && (! $source || $block), 422, 'Choose a block in this program to duplicate.');
            $fields = [...collect($row)->only('name', 'start_week', 'end_week', 'type', 'description')->all(), 'position' => $position];
            if ($block) {
                $block->fill($fields);
                abort_if($coach && $block->isDirty() && ! $this->coachCanEditBlock($block->fresh()), 403, 'Current and completed blocks are protected.');
                $block->save();
            } else {
                $block = $plan->blocks()->create($fields);
                if ($source) {
                    foreach ($source->trainingDays as $day) {
                        app(TrainingPlanDuplicator::class)->dayCopy($day, $plan, $day->title, $day->position, $paths, $block->id);
                    }
                }
            }
            $kept[] = $block->id;
        }
        foreach ($existing as $block) {
            if (! in_array($block->id, $kept, true)) {
                abort_if($coach && ! $this->coachCanEditBlock($block), 403, 'Current and completed blocks are protected.');
                // Run prescriptions and media are independent. Keep them when a definition is removed.
                $oldPaths = $block->trainingDays()->with('exercises')->get()->flatMap(fn ($day) => $day->exercises->flatMap(fn ($exercise) => [$exercise->thumbnail_path, $exercise->custom_icon_path]))->filter()->all();
                DB::afterCommit(fn () => Storage::disk('public')->delete($oldPaths));
                $block->trainingDays()->delete();
                $block->delete();
            }
        }
        $plan->update(['duration_weeks' => $duration]);
    }
}
