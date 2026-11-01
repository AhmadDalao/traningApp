<?php

namespace App\Services;

use App\Models\CoachSubscription;
use App\Models\CoachSubscriptionPlan;
use App\Models\CoachTraineeRelationship;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/** Shared subscription rules for administration and the trainee setup flow. */
class CoachingSubscriptions
{
    public static function rules(): array
    {
        return ['package_id' => 'nullable|integer', 'package_name' => 'required_without:package_id|nullable|string|max:100', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'nullable|date_format:Y-m-d|after_or_equal:starts_on', 'open_ended' => 'sometimes|boolean', 'notes' => 'nullable|string|max:3000'];
    }

    public function start(User $coach, User $trainee, array $data, bool $resumeSetup = false, ?int $expectedRelationshipId = null): CoachSubscription
    {
        return app(CoachAccess::class)->transaction($coach, $trainee, function ($relationship) use ($coach, $data, $resumeSetup, $expectedRelationshipId) {
            abort_if($expectedRelationshipId !== null && $relationship->id !== $expectedRelationshipId, 403);
            $existing = $relationship->subscriptions()->whereIn('status', ['active', 'paused'])->lockForUpdate()->first();
            if ($resumeSetup && $existing) {
                return $existing;
            }
            $this->available($relationship);
            $package = ! empty($data['package_id']) ? CoachSubscriptionPlan::where('coach_user_id', $coach->id)->where('active', true)->lockForUpdate()->findOrFail($data['package_id']) : null;
            $ends = $data['ends_on'] ?? ($package?->duration_days ? Carbon::parse($data['starts_on'])->addDays($package->duration_days - 1)->toDateString() : null);
            if ($data['open_ended'] ?? false) {
                $ends = null;
            }
            if (! $ends && ! ($data['open_ended'] ?? false)) {
                throw ValidationException::withMessages(['ends_on' => 'Choose an end date or explicitly select open-ended coaching.']);
            }

            return $relationship->subscriptions()->create(['coach_subscription_plan_id' => $package?->id, 'package_name' => $package?->name ?? $data['package_name'], 'starts_on' => $data['starts_on'], 'ends_on' => $ends, 'notes' => $data['notes'] ?? null, 'status' => 'active']);
        }, false);
    }

    /** Read-only proposal. The package is never edited for an individual subscription. */
    public function setupReview(CoachTraineeRelationship $relationship, array $choices, ?string $required): array
    {
        $current = $relationship->subscriptions()->whereIn('status', ['active', 'paused'])
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', today()))->latest('id')->lockForUpdate()->first()
            ?? $relationship->subscriptions()->latest('id')->lockForUpdate()->first();
        $valid = $current && in_array($current->status, ['active', 'paused'], true) && (! $current->ends_on || $current->ends_on->gte(today()));
        $operation = ! $valid ? ($current ? 'renew' : 'activate') : ($current->status === 'paused' ? 'resume' : ($current->starts_on->gt(today()) ? 'activate' : 'reuse'));
        $package = ! empty($choices['package_id']) ? CoachSubscriptionPlan::where('coach_user_id', $relationship->coach_user_id)->where('active', true)->lockForUpdate()->find($choices['package_id']) : null;
        $issues = [];
        if (! empty($choices['package_id']) && ! $package) {
            $issues[] = ['code' => 'package_unavailable', 'field' => 'package_id', 'message' => 'Selected Coaching Package is no longer available. Choose another package.'];
        }
        $starts = $valid ? min($current->starts_on->toDateString(), today()->toDateString()) : today()->toDateString();
        $baseEnd = $valid ? $current->ends_on?->toDateString() : ($package
            ? ($package->duration_days ? today()->addDays($package->duration_days - 1)->toDateString() : null)
            : (($choices['open_ended'] ?? false) ? null : ($choices['coaching_ends_on'] ?? $required)));
        $ongoing = $valid ? $current->ends_on === null : ($package ? ! $package->duration_days : (bool) ($choices['open_ended'] ?? false));
        if (! $ongoing && ! $baseEnd) {
            $issues[] = ['code' => 'coverage_missing', 'field' => 'coaching_ends_on', 'message' => 'Choose Coaching Subscription coverage or ongoing coaching.'];
        }
        $extension = ! $ongoing && $required && (! $baseEnd || $baseEnd < $required);
        $ends = $extension && ($choices['include_extension'] ?? false) ? $required : $baseEnd;
        if ($extension && ! ($choices['include_extension'] ?? false)) {
            $issues[] = ['code' => 'coverage_extension', 'field' => 'include_extension', 'message' => 'Include the Coaching Subscription extension through '.$required.' before activating.'];
        }
        if ($ends && $ends < today()->toDateString()) {
            $issues[] = ['code' => 'coverage_elapsed', 'field' => 'coaching_ends_on', 'message' => 'Coaching Subscription coverage must include today.'];
        }

        return ['operation' => $operation, 'current' => $current?->summary(),
            'starts_on' => $starts, 'ends_on' => $ongoing ? null : $ends, 'base_ends_on' => $baseEnd,
            'required_through' => $required, 'extension_days' => $extension && $baseEnd ? (int) Carbon::parse($baseEnd)->diffInDays(Carbon::parse($required)) : 0,
            'extension_required' => $extension, 'extension_included' => (bool) ($choices['include_extension'] ?? false),
            'package_id' => $valid ? $current->coach_subscription_plan_id : $package?->id,
            'package_name' => $valid ? $current->package_name : ($package?->name ?? ($choices['package_name'] ?? 'Coaching')),
            'issues' => $issues, 'fingerprint' => [$current?->getAttributes(), $package?->getAttributes()]];
    }

    /** Caller holds the relationship lock and has revalidated the signed setup review. */
    public function applySetup(User $coach, User $trainee, CoachTraineeRelationship $relationship, array $review): CoachSubscription
    {
        if (in_array($review['operation'], ['reuse', 'resume'], true) || ($review['operation'] === 'activate' && $review['current'])) {
            $subscription = $relationship->subscriptions()->lockForUpdate()->findOrFail($review['current']['id']);
            $subscription->fill(['status' => 'active', 'starts_on' => $review['starts_on'], 'ends_on' => $review['ends_on']]);
            if ($subscription->isDirty()) {
                $subscription->save();
            }

            return $subscription;
        }

        return $this->start($coach, $trainee, ['package_id' => $review['package_id'], 'package_name' => $review['package_name'],
            'starts_on' => today()->toDateString(), 'ends_on' => $review['ends_on'], 'open_ended' => $review['ends_on'] === null], expectedRelationshipId: $relationship->id);
    }

    public function available(CoachTraineeRelationship $relationship, ?int $except = null): void
    {
        if ($relationship->subscriptions()->whereIn('status', ['active', 'paused'])->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', today()))->when($except, fn ($q) => $q->whereKeyNot($except))->lockForUpdate()->exists()) {
            throw ValidationException::withMessages(['subscription' => 'End the existing active or paused subscription before starting another.']);
        }
    }
}
