<?php

namespace App\Http\Controllers;

use App\Models\ProgramRun;
use App\Models\TrainingPlan;
use App\Models\User;
use App\Services\ProgramDefinition;
use App\Services\ProgramLifecycle;
use App\Services\ProgramSchedule;
use App\Services\TrainingContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ProgramController extends Controller
{
    public function __construct(private TrainingContext $context) {}

    public function blocks(Request $request, TrainingPlan $trainingPlan, ProgramDefinition $definition)
    {
        $this->context->plan($trainingPlan, 'update');
        abort_unless($trainingPlan->isProgram(), 422);
        $data = $request->validate(['blocks' => 'required|array|max:52', 'duration_weeks' => 'required|integer|between:1,52']);
        $paths = [];
        try {
            $this->context->write(function () use ($trainingPlan, $definition, $data, &$paths) {
                $plan = TrainingPlan::lockForUpdate()->findOrFail($trainingPlan->id);
                $this->context->plan($plan, 'update');
                $definition->saveBlocks($plan, $data['blocks'], $data['duration_weeks'], $this->context->coaching(), $paths);
            });
        } catch (\Throwable $error) {
            Storage::disk('public')->delete($paths);
            throw $error;
        }

        return back()->with('success', 'Program definition saved. Review changes on each active run before updating its schedule.');
    }

    public function startPage(TrainingPlan $trainingPlan)
    {
        $this->context->plan($trainingPlan, 'update');
        abort_if($this->context->templates() || ! $trainingPlan->isProgram(), 403);

        return Inertia::render('Programs/Start', ['plan' => $trainingPlan]);
    }

    public function preview(Request $request, TrainingPlan $trainingPlan, ProgramSchedule $schedule)
    {
        $this->context->plan($trainingPlan, 'update');
        abort_if($this->context->templates(), 403);
        $data = $request->validate(['start_date' => 'required|date_format:Y-m-d|after_or_equal:today', 'nonce' => 'required|uuid']);

        return response()->json(['preview' => $schedule->preview($trainingPlan, $data['start_date'], $data['nonce'], $this->context->coaching() ? $request->user() : null)]);
    }

    public function start(Request $request, TrainingPlan $trainingPlan, ProgramSchedule $schedule)
    {
        $this->context->plan($trainingPlan, 'update');
        abort_if($this->context->templates(), 403);
        $data = $request->validate(['start_date' => 'required|date_format:Y-m-d|after_or_equal:today', 'nonce' => 'required|uuid', 'signature' => 'required|string|size:64', 'confirmed' => 'required|accepted']);
        $paths = [];
        try {
            $run = $this->context->write(function () use ($trainingPlan, $schedule, $data, &$paths) {
                User::whereKey($this->context->owner()->id)->lockForUpdate()->firstOrFail();
                $plan = TrainingPlan::lockForUpdate()->findOrFail($trainingPlan->id);
                $this->context->plan($plan, 'update');

                return $schedule->start($plan, $data, $this->context->coaching() ? $this->context->actor() : null, $paths);
            });
        } catch (\Throwable $error) {
            Storage::disk('public')->delete($paths);
            throw $error;
        }

        return response()->json(['url' => $this->context->url('/program-runs/'.$run->id)]);
    }

    private function authorizeRun(ProgramRun $run, bool $manage = false): void
    {
        abort_if($this->context->templates(), 403);
        abort_unless($run->user_id === $this->context->owner()->id, 403);
        if ($this->context->coaching()) {
            abort_unless($run->trainingPlan, 403);
            $this->context->plan($run->trainingPlan, $manage ? 'update' : 'view');
        }
    }

    public function index(ProgramLifecycle $lifecycle)
    {
        abort_if($this->context->templates(), 403);
        $runs = ProgramRun::where('user_id', $this->context->owner()->id)
            ->when($this->context->coaching(), fn ($q) => $q->whereIn('training_plan_id', $this->context->plans()->select('training_plans.id')))
            ->latest('id')->paginate(12)->through(function ($run) use ($lifecycle) {
                $run = $lifecycle->sync($run->id);

                return [...$run->toArray(), 'position' => collect($lifecycle->position($run))->except('sessions')->all()];
            });

        return Inertia::render('Programs/Index', ['runs' => $runs]);
    }

    public function show(ProgramRun $programRun, ProgramLifecycle $lifecycle)
    {
        $this->authorizeRun($programRun);
        $run = $lifecycle->sync($programRun->id);

        return Inertia::render('Programs/Run', ['run' => $run, 'position' => $lifecycle->position($run),
            'summary' => $run->status === 'completed' ? $lifecycle->summary($run) : null]);
    }

    public function changePreview(Request $request, ProgramRun $programRun, ProgramSchedule $schedule)
    {
        return $this->change($request, $programRun, $schedule, false);
    }

    public function changeApply(Request $request, ProgramRun $programRun, ProgramSchedule $schedule)
    {
        return $this->change($request, $programRun, $schedule, true);
    }

    private function change(Request $request, ProgramRun $run, ProgramSchedule $schedule, bool $apply)
    {
        $this->authorizeRun($run, true);
        abort_unless($run->trainingPlan, 422, 'The definition was deleted; this run keeps its saved workouts.');
        $data = $request->validate(['signature' => ($apply ? 'required' : 'nullable').'|string|size:64', 'confirmed' => $apply ? 'required|accepted' : 'sometimes|boolean']);
        $paths = [];
        try {
            return $this->context->write(function () use ($run, $schedule, $apply, $data, &$paths) {
                User::whereKey($this->context->owner()->id)->lockForUpdate()->firstOrFail();
                $plan = TrainingPlan::lockForUpdate()->findOrFail($run->training_plan_id);
                $run = ProgramRun::lockForUpdate()->findOrFail($run->id);
                $this->authorizeRun($run, true);
                $coach = $this->context->coaching() ? $this->context->actor() : null;
                if ($apply) {
                    $schedule->applyChanges($run, $plan, $data['signature'], $coach, $paths);

                    return response()->json(['saved' => true]);
                }
                $preview = $schedule->changes($run, $plan, $coach);
                unset($preview['definition']);
                foreach (['add', 'update', 'remove', 'keep', 'protected'] as $group) {
                    $preview[$group] = $schedule->publicRows($preview[$group]);
                }

                return response()->json(['preview' => $preview]);
            });
        } catch (\Throwable $error) {
            Storage::disk('public')->delete($paths);
            throw $error;
        }
    }
}
