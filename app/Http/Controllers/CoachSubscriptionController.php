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
        $filters = $request->validate(['search' => 'nullable|string|max:100', 'status' => 'nullable|in:all,active,paused,ended,expired,upcoming', 'per_page' => 'nullable|in:10,25,50,100', 'trainee' => 'nullable|integer|min:1', 'coverage' => 'nullable|in:all,ending_7,ending_30,ongoing', 'sort' => 'nullable|in:newest,ending_first,starting_first']);
        $filters = [...['search' => '', 'status' => 'all', 'per_page' => 10, 'trainee' => '', 'coverage' => 'all', 'sort' => 'newest'], ...array_filter($filters, fn ($value) => $value !== null)];
        $query = CoachSubscription::whereHas('relationship', fn ($q) => $q->where('coach_user_id', $request->user()->id));
        $state = "CASE WHEN status <> 'active' THEN status WHEN starts_on > ? THEN 'upcoming' WHEN ends_on < ? THEN 'expired' ELSE 'active' END";
        $counts = (clone $query)->selectRaw("$state as effective, COUNT(*) as total", [today()->toDateString(), today()->toDateString()])->groupBy('effective')->pluck('total', 'effective');
        $query->when($filters['status'] !== 'all', fn ($q) => $q->whereRaw("($state) = ?", [today()->toDateString(), today()->toDateString(), $filters['status']]))
            ->when($filters['trainee'], fn ($q) => $q->whereHas('relationship', fn ($q) => $q->where('trainee_user_id', $filters['trainee'])))
            ->when($filters['search'], fn ($q) => $q->where(fn ($q) => $q->where('package_name', 'like', '%'.$filters['search'].'%')
                ->orWhereHas('relationship.trainee', fn ($q) => $q->where('name', 'like', '%'.$filters['search'].'%')->orWhere('training_code', 'like', '%'.$filters['search'].'%'))
                ->orWhereHas('assignment.plan', fn ($q) => $q->where('name', 'like', '%'.$filters['search'].'%'))));
        if (in_array($filters['coverage'], ['ending_7', 'ending_30'])) {
            $query->whereRaw("($state) = 'active'", [today()->toDateString(), today()->toDateString()])
                ->whereBetween('ends_on', [today()->toDateString(), today()->addDays($filters['coverage'] === 'ending_7' ? 7 : 30)->toDateString()]);
        } elseif ($filters['coverage'] === 'ongoing') {
            $query->whereNull('ends_on')->whereIn('status', ['active', 'paused']);
        }
        match ($filters['sort']) {
            'ending_first' => $query->orderByRaw('CASE WHEN ends_on IS NULL THEN 1 ELSE 0 END')->orderBy('ends_on'),
            'starting_first' => $query->orderBy('starts_on'),
            default => null,
        };

        return Inertia::render('Coach/Subscriptions', [
            'packages' => CoachSubscriptionPlan::where('coach_user_id', $request->user()->id)->latest()->get(),
            'relationships' => CoachTraineeRelationship::where('coach_user_id', $request->user()->id)->where('status', 'active')->with('trainee:id,name,training_code')->get(['id', 'trainee_user_id']),
            'filters' => $filters, 'counts' => $counts,
            'subscriptions' => $query->with(['relationship.trainee:id,name,training_code', 'assignment.plan' => fn ($q) => $q->withThumbnail()])->latest('id')->paginate((int) $filters['per_page'])->withQueryString()->through(fn ($s) => $this->row($s)),
        ]);
    }

    public function row(CoachSubscription $subscription): array
    {
        return [...$subscription->summary(), 'notes' => $subscription->notes,
            'days_until_end' => $subscription->ends_on ? (int) today()->diffInDays($subscription->ends_on, false) : null,
            'days_until_start' => (int) today()->diffInDays($subscription->starts_on, false),
            'assignment_id' => $subscription->coach_plan_assignment_id, 'assignment_status' => $subscription->assignment?->status,
            'plan' => $subscription->assignment?->plan?->only('id', 'name', 'structure_type', 'duration_weeks', 'thumbnail_url'),
            'relationship' => ['status' => $subscription->relationship->status, 'trainee' => $subscription->relationship->trainee->only('id', 'name', 'training_code')]];
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
                app(CoachingSubscriptions::class)->available($relationship, $row->id, $row->assignment);
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
