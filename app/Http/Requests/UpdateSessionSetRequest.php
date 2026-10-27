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
            'actual_reps' => 'sometimes|nullable|integer|between:1,10000',
            'actual_duration_seconds' => 'sometimes|nullable|integer|between:1,86400',
            'actual_weight_kg' => 'sometimes|nullable|numeric|decimal:0,2|between:0,9999.99',
            'completed' => 'sometimes|boolean',
        ];
    }
}
