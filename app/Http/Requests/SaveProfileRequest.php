<?php

namespace App\Http\Requests;

use App\Models\UserProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:100',
            'date_of_birth' => 'nullable|date_format:Y-m-d|before_or_equal:today|after_or_equal:'.today()->subYears(120)->toDateString(),
            'height_cm' => 'nullable|numeric|decimal:0,2|between:40,300',
            'current_weight_kg' => 'nullable|numeric|decimal:0,2|between:20,500',
            'training_goal' => ['nullable', Rule::in(array_keys(UserProfile::GOALS))],
            'body_weight_goal' => ['nullable', Rule::in(array_keys(UserProfile::BODY_GOALS))],
            'target_body_weight_kg' => 'nullable|numeric|decimal:0,2|between:20,500',
            'experience_level' => ['nullable', Rule::in(array_keys(UserProfile::LEVELS))],
            'training_days_per_week' => 'nullable|integer|between:1,7',
            'session_duration_minutes' => ['nullable', Rule::in([30, 45, 60, 90])],
            'equipment_access' => 'nullable|array|max:4',
            'equipment_access.*' => ['required', 'distinct', Rule::in(array_keys(UserProfile::EQUIPMENT))],
        ];
    }
}
