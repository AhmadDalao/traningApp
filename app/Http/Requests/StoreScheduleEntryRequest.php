<?php

namespace App\Http\Requests;

use App\Models\TrainingDay;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreScheduleEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('scheduleEntry') ? $this->user()->can('update', $this->route('scheduleEntry')) : true;
    }

    public function rules(): array
    {
        $historical = $this->route('scheduleEntry') && ! $this->route('scheduleEntry')->training_day_id && $this->route('scheduleEntry')->entry_type === 'training';

        return [
            'entry_type' => ['required', Rule::in(['training', 'rest'])],
            'training_day_id' => [$historical ? 'nullable' : 'required_if:entry_type,training', 'nullable', 'integer',
                function ($attribute, $value, $fail) {
                    if (! TrainingDay::whereKey($value)->whereHas('trainingPlan', fn ($q) => $q->where('user_id', $this->user()->id))->exists()) {
                        $fail('Choose one of your own training days.');
                    }
                }],
            'title' => 'nullable|string|max:150', 'scheduled_date' => 'required|date_format:Y-m-d', 'notes' => 'nullable|string|max:5000',
        ];
    }
}
