<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveBodyWeightRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('bodyWeightEntry') ? $this->user()->can('update', $this->route('bodyWeightEntry')) : $this->user() !== null;
    }

    public function rules(): array
    {
        return ['weight_kg' => 'required|numeric|decimal:0,2|between:20,500', 'recorded_date' => 'required|date_format:Y-m-d|before_or_equal:today', 'note' => 'nullable|string|max:1000'];
    }
}
