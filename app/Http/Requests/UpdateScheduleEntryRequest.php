<?php

namespace App\Http\Requests;

class UpdateScheduleEntryRequest extends StoreScheduleEntryRequest
{
    public function rules(): array
    {
        if ($this->boolean('details_only')) {
            return [
                'details_only' => 'required|accepted',
                'duration_minutes' => 'nullable|integer|between:1,1440',
                'intensity' => 'nullable|in:light,moderate,vigorous',
                'calories_burned' => 'nullable|integer|between:0,20000',
                'calories_source' => 'nullable|in:wearable,exercise_machine,manual',
            ];
        }

        return parent::rules();
    }
}
