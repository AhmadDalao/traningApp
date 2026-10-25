<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateScheduleEntryStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('scheduleEntry'));
    }

    public function rules(): array
    {
        return ['status' => ['required', Rule::in(['planned', 'completed', 'skipped'])]];
    }
}
