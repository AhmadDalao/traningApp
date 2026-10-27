<?php

namespace App\Http\Requests;

class UpdateScheduleEntryRequest extends StoreScheduleEntryRequest
{
    public function rules(): array
    {
        if ($this->boolean('details_only')) {
            return ['details_only' => 'required|accepted', 'duration_minutes' => 'nullable|integer|between:1,1440', 'calories_burned' => 'nullable|integer|between:0,20000'];
        }

        return parent::rules();
    }
}
