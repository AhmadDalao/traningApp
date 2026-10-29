<?php

namespace App\Http\Requests;

use App\Services\ExerciseLibrary;
use App\Services\TrainingContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTrainingDayRequest extends FormRequest
{
    public function authorize(): bool
    {
        $context = app(TrainingContext::class);
        $this->route('trainingDay') ? $context->day($this->route('trainingDay'), 'update') : $context->plan($this->route('trainingPlan'), 'update');

        return true;
    }

    public function rules(): array
    {
        return [
            'training_block_id' => ['nullable', 'integer', Rule::exists('training_blocks', 'id')->where('training_plan_id', $this->route('trainingDay')?->training_plan_id ?? $this->route('trainingPlan')?->id ?? 0)],
            'icon' => ['nullable', Rule::in(config('training.icons'))],
            'exercises.*.icon' => ['nullable', Rule::in(config('training.icons'))],
            'title' => 'required|string|max:150', 'type' => ['required', Rule::in(['training', 'rest'])],
            'focus_area' => ['nullable', Rule::in(['Chest', 'Back', 'Shoulders', 'Arms', 'Legs', 'Core', 'Cardio', 'Full Body', 'Mobility', 'Other'])],
            'notes' => 'nullable|string|max:5000', 'exercises' => 'nullable|array|max:100',
            'weekdays' => 'nullable|array|max:7',
            'weekdays.*' => 'required|integer|between:0,6|distinct',
            'default_time' => 'nullable|date_format:H:i',
            'exercises.*.id' => ['nullable', 'integer', 'distinct', Rule::exists('training_exercises', 'id')->where('training_day_id', $this->route('trainingDay')?->id ?? 0)],
            'exercises.*.name' => 'required|string|max:150', 'exercises.*.sets' => 'required|integer|min:1|max:99',
            'exercises.*.reps' => 'required|string|max:100', 'exercises.*.superset_group' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9]+$/'],
            'exercises.*.tracking_mode' => 'nullable|in:reps,duration',
            'exercises.*.notes' => 'nullable|string|max:2000',
            'exercises.*.target_weight' => 'nullable|numeric|decimal:0,2|min:0|max:'.config('training.limits.weight_kg'),
            'exercises.*.thumbnail' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'exercises.*.remove_thumbnail' => 'nullable|boolean',
            'exercises.*.library_key' => ['nullable', 'string', 'max:255', function ($attribute, $value, $fail) {
                $index = explode('.', $attribute)[1];
                $existing = $this->route('trainingDay')?->exercises()->whereKey($this->input('exercises.'.$index.'.id'))->first();
                if ($existing && $existing->library_key === $value) {
                    return;
                }
                if (! app(ExerciseLibrary::class)->find($value, $this->user()->id)) {
                    $fail('Choose an exercise from your library, or create a custom exercise.');
                }
            }],
            'exercises.*.save_to_library' => 'nullable|boolean',
            'exercises.*.custom_icon' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:512|dimensions:min_width=64,min_height=64,max_width=512,max_height=512,ratio=1/1',
            'exercises.*.remove_custom_icon' => 'nullable|boolean',
        ];
    }
}
