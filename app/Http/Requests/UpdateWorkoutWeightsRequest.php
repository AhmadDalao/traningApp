<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkoutWeightsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('scheduleEntry'));
    }

    public function rules(): array
    {
        return [
            'weights' => 'required|array|min:1|max:100',
            'weights.*' => 'required|array:index,actual_weight',
            'weights.*.index' => 'required|integer|min:0|distinct',
            'weights.*.actual_weight' => 'present|nullable|numeric|decimal:0,2|min:0|max:9999.99',
        ];
    }
}
