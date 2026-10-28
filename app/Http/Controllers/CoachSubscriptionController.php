<?php

namespace App\Http\Controllers;

use App\Models\CoachSubscription;
use App\Models\CoachSubscriptionPlan;
use App\Models\CoachTraineeRelationship;
use App\Services\CoachAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class CoachSubscriptionController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('Coach/Subscriptions', [
            'packages' => CoachSubscriptionPlan::where('coach_user_id', $request->user()->id)->latest()->get(),
            'relationships' => CoachTraineeRelationship::where('coach_user_id', $request->user()->id)->where('status', 'active')->with('trainee:id,name,training_code')->get(['id', 'trainee_user_id']),
            'subscriptions' => CoachSubscription::whereHas('relationship', fn ($q) => $q->where('coach_user_id', $request->user()->id))->with('relationship.trainee:id,name,training_code')->latest()->paginate(20)->through(fn ($s) => [...$s->toArray(), 'effective_status' => $s->effective_status]),
        ]);
    }

    public function package(Request $request, ?CoachSubscriptionPlan $package = null)
    {
        abort_if($package && $package->coach_user_id !== $request->user()->id, 403);
        $data = $request->validate(['name' => 'required|string|max:100', 'description' => 'nullable|string|max:2000', 'duration_days' => 'nullable|integer|between:1,1095', 'active' => 'required|boolean']);
        $package ? $package->update($data) : CoachSubscriptionPlan::create([...$data, 'coach_user_id' => $request->user()->id]);

        return back()->with('success', 'Coaching package saved. Existing subscriptions are unchanged.');
    }

    public function store(Request $request, CoachAccess $access)
    {
        $data = $request->validate(['relationship_id' => 'required|integer', 'package_id' => 'nullable|integer', 'package_name' => 'required_without:package_id|nullable|string|max:100', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'nullable|date_format:Y-m-d|after_or_equal:starts_on', 'open_ended' => 'sometimes|boolean', 'notes' => 'nullable|string|max:3000']);
        $relationship = CoachTraineeRelationship::where('coach_user_id', $request->user()->id)->findOrFail($data['relationship_id']);
        $access->transaction($request->user(), $relationship->trainee, function ($current) use ($data, $relationship, $request) {
            abort_unless($current->id === $relationship->id, 403);
            $this->available($current);
            $package = ! empty($data['package_id']) ? CoachSubscriptionPlan::where('coach_user_id', $request->user()->id)->where('active', true)->lockForUpdate()->findOrFail($data['package_id']) : null;
            $ends = $data['ends_on'] ?? ($package?->duration_days ? Carbon::parse($data['starts_on'])->addDays($package->duration_days - 1)->toDateString() : null);
            if ($request->boolean('open_ended')) {
                $ends = null;
            }
            if (! $ends && ! $request->boolean('open_ended')) {
                throw ValidationException::withMessages(['ends_on' => 'Choose an end date or explicitly select open-ended coaching.']);
            }
            $current->subscriptions()->create(['coach_subscription_plan_id' => $package?->id, 'package_name' => $package?->name ?? $data['package_name'], 'starts_on' => $data['starts_on'], 'ends_on' => $ends, 'notes' => $data['notes'] ?? null, 'status' => 'active']);
        }, false);

        return back()->with('success', 'Coaching subscription created.');
    }

    private function available(CoachTraineeRelationship $relationship, ?int $except = null): void
    {
        if ($relationship->subscriptions()->whereIn('status', ['active', 'paused'])->when($except, fn ($q) => $q->whereKeyNot($except))->lockForUpdate()->exists()) {
            throw ValidationException::withMessages(['subscription' => 'End the existing active or paused subscription before starting another.']);
        }
    }

    public function update(Request $request, CoachSubscription $subscription, CoachAccess $access)
    {
        abort_unless($subscription->relationship->coach_user_id === $request->user()->id, 403);
        $data = $request->validate(['action' => 'required|in:pause,resume,end', 'confirmed' => 'exclude_unless:action,end|required|accepted']);
        $access->transaction($request->user(), $subscription->relationship->trainee, function ($relationship) use ($subscription, $data) {
            abort_unless($subscription->coach_trainee_relationship_id === $relationship->id, 403);
            $row = CoachSubscription::lockForUpdate()->findOrFail($subscription->id);
            if ($row->status === 'ended') {
                throw ValidationException::withMessages(['subscription' => 'This subscription has ended. Start a new one.']);
            }
            if ($data['action'] === 'resume') {
                $this->available($relationship, $row->id);
                if ($row->ends_on && $row->ends_on->lt(today())) {
                    throw ValidationException::withMessages(['subscription' => 'This subscription has expired. End it and start a new one.']);
                }
            }
            $row->update(['status' => match ($data['action']) {
                'pause' => 'paused', 'resume' => 'active', default => 'ended'
            }, 'ended_at' => $data['action'] === 'end' ? now() : null]);
        }, false);

        return back()->with('success', 'Subscription updated. Training data is unchanged.');
    }
}
