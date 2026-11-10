<?php

namespace App\Services;

use App\Models\User;

class ProfileSettings
{
    // Called inside the caller's transaction after the account row is locked.
    public function save(User $user, array $data, bool $complete = false): void
    {
        if (array_key_exists('training_goals', $data)) {
            $data['training_goals'] ??= [];
            $data['training_goal'] = $data['training_goals'][0] ?? null;
        } elseif (array_key_exists('training_goal', $data)) {
            $data['training_goals'] = $data['training_goal'] ? [$data['training_goal']] : [];
        }
        if (isset($data['name'])) {
            $user->update(['name' => $data['name']]);
        }
        $weight = $data['current_weight_kg'] ?? null;
        unset($data['name'], $data['current_weight_kg']);
        $profile = $user->profile()->firstOrCreate();
        if (! in_array(array_key_exists('body_weight_goal', $data) ? $data['body_weight_goal'] : $profile->body_weight_goal, ['lose', 'gain'], true)) {
            $data['target_body_weight_kg'] = null;
        }
        if ($complete) {
            $data = [...$data, 'onboarding_completed_at' => now(), 'onboarding_skipped_at' => null];
        }
        $profile->update($data);
        if ($weight !== null && ($profile->current_weight_kg === null || (float) $weight !== (float) $profile->current_weight_kg || ! $user->bodyWeightEntries()->exists())) {
            $user->bodyWeightEntries()->create(['weight_kg' => $weight, 'recorded_date' => today()->toDateString()]);
            app(BodyWeightHistory::class)->sync($user);
        }
    }
}
