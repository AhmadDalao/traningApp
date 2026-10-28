<?php

namespace App\Http\Requests;

use App\Services\TrainingContext;
use Illuminate\Foundation\Http\FormRequest;

class StoreTrainingPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($plan = $this->route('trainingPlan')) {
            app(TrainingContext::class)->plan($plan, 'update');
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $mode = $this->input('schedule_mode', $this->route('trainingPlan')?->schedule_mode ?? 'fixed');
        $this->merge(['schedule_mode' => $mode]);
        if ($mode === 'ongoing') {
            $stop = $this->route('trainingPlan')?->end_date?->toDateString();
            $this->merge(['end_date' => $stop && $this->input('end_date') === $stop ? $stop : null]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:150', 'description' => 'nullable|string|max:5000',
            'schedule_mode' => 'required|in:fixed,ongoing',
            'start_date' => 'nullable|required_if:schedule_mode,ongoing|required_with:end_date|date_format:Y-m-d',
            'end_date' => $this->input('schedule_mode') === 'ongoing' ? 'nullable|date_format:Y-m-d' : 'nullable|required_with:start_date|date_format:Y-m-d|after_or_equal:start_date',
        ];
    }
}
