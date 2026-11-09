<?php

namespace App\Http\Requests;

class UpdateTrainingDayRequest extends StoreTrainingDayRequest
{
    public function rules(): array
    {
        if (! $this->boolean('schedule_only')) {
            return parent::rules();
        }

        return [
            'schedule_only' => 'required|accepted',
            'weekdays' => 'present|array|max:7', 'weekdays.*' => 'integer|between:0,6|distinct',
            'default_time' => 'nullable|date_format:H:i',
            'expected_weekdays' => 'present|array|max:7', 'expected_weekdays.*' => 'integer|between:0,6|distinct',
            'expected_default_time' => 'nullable|date_format:H:i',
            ...array_fill_keys(['title', 'type', 'focus_area', 'icon', 'notes', 'training_block_id', 'exercises'], 'prohibited'),
        ];
    }
}
