<?php

namespace App\Http\Requests;

use App\Models\TrainingDay;
use App\Services\CoachAccess;
use App\Services\TrainingContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreScheduleEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $context = app(TrainingContext::class);
        if ($context->coaching()) {
            if ($entry = $this->route('scheduleEntry')) {
                app(CoachAccess::class)->futureSession($this->user(), $context->owner(), $entry);
            }

            return true;
        }

        return $this->route('scheduleEntry') ? $this->user()->can('update', $this->route('scheduleEntry')) : true;
    }

    public function rules(): array
    {
        $context = app(TrainingContext::class);
        $historical = $this->route('scheduleEntry') && ! $this->route('scheduleEntry')->training_day_id && $this->route('scheduleEntry')->entry_type === 'training';

        return [
            'entry_type' => ['required', Rule::in(['training', 'rest'])],
            'training_day_id' => [$context->coaching() ? 'required' : ($historical ? 'nullable' : 'required_if:entry_type,training'), 'nullable', 'integer',
                function ($attribute, $value, $fail) {
                    if (! TrainingDay::whereKey($value)->whereIn('training_plan_id', app(TrainingContext::class)->plans(true)->select('training_plans.id'))->exists()) {
                        $fail('Choose one of your own training days.');
                    }
                }],
            'title' => 'nullable|string|max:150', 'scheduled_date' => 'required|date_format:Y-m-d', 'notes' => 'nullable|string|max:5000',
            'scheduled_time' => 'nullable|date_format:H:i',
        ];
    }
}
