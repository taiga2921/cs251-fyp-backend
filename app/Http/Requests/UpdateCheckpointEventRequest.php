<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCheckpointEventRequest extends FormRequest
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
            'patrol_session_id' => ['sometimes', 'required', 'uuid', 'exists:patrol_sessions,id'],
            'checkpoint_id' => ['sometimes', 'required', 'uuid', 'exists:checkpoints,id'],
            'entered_at' => ['sometimes', 'nullable', 'date'],
            'exited_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:entered_at'],
            'detected_at' => ['sometimes', 'nullable', 'date'],
            'processed_at' => ['sometimes', 'nullable', 'date'],
            'detection_type' => ['sometimes', 'nullable', 'in:continuous,resume'],
            'confidence_score' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'status' => ['sometimes', 'nullable', 'in:pending,verified,suspicious,uncertain,rejected'],
        ];
    }
}
