<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTrainingDayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('trainingDay') ? $this->user()->can('update', $this->route('trainingDay')) : $this->user()->can('update', $this->route('trainingPlan'));
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:150', 'type' => ['required', Rule::in(['training', 'rest'])],
            'focus_area' => ['nullable', Rule::in(['Chest', 'Back', 'Shoulders', 'Arms', 'Legs', 'Core', 'Cardio', 'Full Body', 'Mobility', 'Other'])],
            'notes' => 'nullable|string|max:5000', 'exercises' => 'nullable|array|max:100',
            'exercises.*.id' => ['nullable', 'integer', 'distinct', Rule::exists('training_exercises', 'id')->where('training_day_id', $this->route('trainingDay')?->id ?? 0)],
            'exercises.*.name' => 'required|string|max:150', 'exercises.*.sets' => 'required|integer|min:1|max:99',
            'exercises.*.reps' => 'required|string|max:100', 'exercises.*.superset_group' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9]+$/'],
            'exercises.*.notes' => 'nullable|string|max:2000',
            'exercises.*.thumbnail' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'exercises.*.remove_thumbnail' => 'nullable|boolean',
        ];
    }
}
