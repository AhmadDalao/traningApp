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

    public function available(CoachTraineeRelationship $relationship, ?int $except = null): void
    {
        if ($relationship->subscriptions()->whereIn('status', ['active', 'paused'])->when($except, fn ($q) => $q->whereKeyNot($except))->lockForUpdate()->exists()) {
            throw ValidationException::withMessages(['subscription' => 'End the existing active or paused subscription before starting another.']);
        }
    }
}
