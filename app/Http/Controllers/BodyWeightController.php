<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveBodyWeightRequest;
use App\Models\BodyWeightEntry;
use App\Models\User;
use App\Services\BodyWeightHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class BodyWeightController extends Controller
{
    public function store(SaveBodyWeightRequest $request, BodyWeightHistory $history)
    {
        DB::transaction(function () use ($request, $history) {
            $user = User::lockForUpdate()->findOrFail($request->user()->id);
            $user->bodyWeightEntries()->create($request->validated());
            $history->sync($user);
        });

        return back()->with('success', 'Body weight recorded.');
    }

    public function update(SaveBodyWeightRequest $request, BodyWeightEntry $bodyWeightEntry, BodyWeightHistory $history)
    {
        DB::transaction(function () use ($request, $bodyWeightEntry, $history) {
            $user = User::lockForUpdate()->findOrFail($request->user()->id);
            $user->bodyWeightEntries()->findOrFail($bodyWeightEntry->id)->update($request->validated());
            $history->sync($user);
        });

        return back()->with('success', 'Body weight updated.');
    }

    public function destroy(Request $request, BodyWeightEntry $bodyWeightEntry, BodyWeightHistory $history)
    {
        Gate::authorize('delete', $bodyWeightEntry);
        DB::transaction(function () use ($request, $bodyWeightEntry, $history) {
            $user = User::lockForUpdate()->findOrFail($request->user()->id);
            $user->bodyWeightEntries()->findOrFail($bodyWeightEntry->id)->delete();
            $history->sync($user);
        });

        return back()->with('success', 'Body weight entry deleted.');
    }

    public function dismiss(Request $request)
    {
        $request->user()->profile()->updateOrCreate([], ['weight_check_in_dismissed_until' => today()->addDays(7)]);

        return back();
    }
}
