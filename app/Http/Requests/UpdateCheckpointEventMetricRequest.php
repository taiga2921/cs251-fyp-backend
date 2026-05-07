<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCheckpointEventMetricRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'checkpoint_event_id' => [
                'sometimes',
                'required',
                'uuid',
                'exists:checkpoint_events,id',
                Rule::unique('checkpoint_event_metrics', 'checkpoint_event_id')->ignore($this->route('checkpoint_event_metric')),
            ],
            'distance_score' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'accuracy_score' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'time_score' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'stability_score' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'gap_factor' => ['sometimes', 'required', 'numeric', 'min:0', 'max:1'],
            'integrity_factor' => ['sometimes', 'required', 'numeric', 'min:0', 'max:1'],
        ];
    }
}
