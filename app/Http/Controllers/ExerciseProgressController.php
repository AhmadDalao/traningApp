<?php

namespace App\Http\Controllers;

use App\Services\ExerciseProgress;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ExerciseProgressController extends Controller
{
    public function __invoke(Request $request, ExerciseProgress $progress)
    {
        $data = $request->validate([
            'exercise' => 'nullable|string|max:255', 'search' => 'nullable|string|max:150',
            'mode' => ['nullable', Rule::in(['reps', 'duration'])],
            'range' => ['nullable', Rule::in(['30', '90', '6m', '1y', 'all'])],
            'page' => 'nullable|integer|min:1',
        ]);
        if (! empty($data['exercise'])) {
            return Inertia::render('ExerciseProgress/Show', $progress->detail(
                $request->user(), $data['exercise'], $data['mode'] ?? 'reps', $data['range'] ?? '90',
            ));
        }

        return Inertia::render('ExerciseProgress/Index', [
            ...$progress->overview($request->user(), $data['search'] ?? ''), 'search' => $data['search'] ?? '',
        ]);
    }
}
