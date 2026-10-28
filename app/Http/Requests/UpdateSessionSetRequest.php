<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSessionSetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('sessionSet')->sessionExercise->scheduleEntry);
    }

    public function rules(): array
    {
        return [
            'actual_reps' => 'sometimes|nullable|integer|between:1,'.config('training.limits.reps'),
            'actual_duration_seconds' => 'sometimes|nullable|integer|between:1,'.config('training.limits.duration_seconds'),
            'actual_weight_kg' => 'sometimes|nullable|numeric|decimal:0,2|between:0,'.config('training.limits.weight_kg'),
            'completed' => 'sometimes|boolean',
        ];
    }
}
