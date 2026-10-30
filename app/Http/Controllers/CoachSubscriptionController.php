<?php

namespace App\Http\Controllers;

use App\Models\CoachSubscription;
use App\Models\CoachSubscriptionPlan;
use App\Models\CoachTraineeRelationship;
use App\Services\CoachAccess;
use App\Services\CoachingSubscriptions;
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

    public function store(Request $request, CoachingSubscriptions $subscriptions)
    {
        $data = $request->validate(['relationship_id' => 'required|integer', ...$subscriptions::rules()]);
        $relationship = CoachTraineeRelationship::where('coach_user_id', $request->user()->id)->findOrFail($data['relationship_id']);
        $subscriptions->start($request->user(), $relationship->trainee, $data, expectedRelationshipId: $relationship->id);

        return back()->with('success', 'Coaching subscription created.');
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
                app(CoachingSubscriptions::class)->available($relationship, $row->id);
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
