<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCheckpointEventRequest extends FormRequest
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
            'patrol_session_id' => ['required', 'uuid', 'exists:patrol_sessions,id'],
            'checkpoint_id' => ['required', 'uuid', 'exists:checkpoints,id'],
            'entered_at' => ['nullable', 'date'],
            'exited_at' => ['nullable', 'date', 'after_or_equal:entered_at'],
            'detected_at' => ['nullable', 'date'],
            'processed_at' => ['nullable', 'date'],
            'detection_type' => ['nullable', 'in:continuous,resume,manual'],
            'confidence_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'status' => ['nullable', 'in:pending,verified,suspicious,uncertain,rejected'],
        ];
    }
}
