<?php

namespace App\Http\Controllers;

use App\Models\TrainingPlan;
use App\Models\User;
use App\Services\PlanSchedule;
use App\Services\TrainingContext;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PlanScheduleController extends Controller
{
    public function __construct(private TrainingContext $context) {}

    public function preview(TrainingPlan $trainingPlan, PlanSchedule $schedule)
    {
        $this->context->plan($trainingPlan, 'update');
        abort_if($this->context->templates(), 403);
        $preview = $schedule->preview($trainingPlan, coach: $this->context->coaching() ? $this->context->actor() : null);

        return Inertia::render('TrainingPlans/Schedule', ['plan' => $trainingPlan, 'preview' => $preview]);
    }

    public function store(Request $request, TrainingPlan $trainingPlan, PlanSchedule $schedule)
    {
        $this->context->plan($trainingPlan, 'update');
        abort_if($this->context->templates(), 403);
        $data = $request->validate([
            'signature' => 'required|string|size:64', 'action' => 'required|in:add,remove,regenerate',
            'confirmed' => 'required|accepted', 'remove_obsolete' => 'sometimes|boolean',
        ]);
        try {
            $result = $this->context->write(function () use ($trainingPlan, $schedule, $data, $request) {
                User::whereKey($this->context->owner()->id)->lockForUpdate()->firstOrFail();
                $plan = TrainingPlan::where('user_id', $this->context->owner()->id)->lockForUpdate()->findOrFail($trainingPlan->id);
                $this->context->plan($plan, 'update');
                $plan->setRelation('trainingDays', $plan->trainingDays()->lockForUpdate()->get());

                return $schedule->apply($plan, $data['signature'], $data['action'], $data['remove_obsolete'] ?? false, $this->context->coaching() ? $request->user() : null);
            });
        } catch (ValidationException $error) {
            return $this->context->redirect('/training-plans/'.$trainingPlan->id.'/schedule-preview')->withErrors($error->errors());
        }

        return $this->context->redirect('/training-plans/'.$trainingPlan->id.'/schedule-preview')->with('success', "{$result['added']} sessions added. {$result['removed']} future generated sessions removed. All protected sessions were kept.");
    }
}
