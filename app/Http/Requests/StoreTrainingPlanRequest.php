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

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:150', 'description' => 'nullable|string|max:5000',
            'start_date' => 'nullable|required_with:end_date|date_format:Y-m-d',
            'end_date' => 'nullable|required_with:start_date|date_format:Y-m-d|after_or_equal:start_date',
        ];
    }
}
