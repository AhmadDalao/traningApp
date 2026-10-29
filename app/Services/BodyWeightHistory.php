<?php

namespace App\Services;

use App\Models\BodyWeightEntry;
use App\Models\User;

class BodyWeightHistory
{
    public function onOrBefore(User $user, string $date): ?BodyWeightEntry
    {
        return $user->bodyWeightEntries()->whereDate('recorded_date', '<=', $date)->orderByDesc('recorded_date')->orderByDesc('id')->first();
    }

    // Call inside a transaction after locking the user, so profile and history agree.
    public function sync(User $user): void
    {
        $latest = $user->bodyWeightEntries()->orderByDesc('recorded_date')->orderByDesc('id')->first();
        $user->profile()->updateOrCreate([], ['current_weight_kg' => $latest?->weight_kg]);
    }

    public function summary(User $user): array
    {
        $profile = $user->profile()->first();
        $first = $user->bodyWeightEntries()->orderBy('recorded_date')->orderBy('id')->first();
        $last = $user->bodyWeightEntries()->orderByDesc('recorded_date')->orderByDesc('id')->first();
        $current = $last?->weight_kg ?? $profile?->current_weight_kg;
        $start = $first?->weight_kg;
        $target = $profile?->target_body_weight_kg;
        $progress = null;
        if ($start !== null && $current !== null && $target !== null
            && (($profile->body_weight_goal === 'lose' && $target < $start) || ($profile->body_weight_goal === 'gain' && $target > $start))) {
            $progress = round(max(0, min(100, (($current - $start) / ($target - $start)) * 100)), 1);
        }

        return ['current' => $current === null ? null : (float) $current, 'starting' => $start === null ? null : (float) $start, 'target' => $target === null ? null : (float) $target, 'change' => $start !== null && $current !== null ? round($current - $start, 2) : null, 'remaining' => $target !== null && $current !== null ? round(abs($current - $target), 2) : null, 'progress' => $progress];
    }
}
