<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCheckpointEventMetricRequest extends FormRequest
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
            'checkpoint_event_id' => ['required', 'uuid', 'exists:checkpoint_events,id', 'unique:checkpoint_event_metrics,checkpoint_event_id'],
            'distance_score' => ['required', 'numeric', 'min:0', 'max:100'],
            'accuracy_score' => ['required', 'numeric', 'min:0', 'max:100'],
            'time_score' => ['required', 'numeric', 'min:0', 'max:100'],
            'stability_score' => ['required', 'numeric', 'min:0', 'max:100'],
            'gap_factor' => ['required', 'numeric', 'min:0', 'max:1'],
            'integrity_factor' => ['required', 'numeric', 'min:0', 'max:1'],
        ];
    }
}
