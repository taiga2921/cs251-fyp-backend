<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SyncPwaLocationLogRequest extends FormRequest
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
            'type' => ['required', 'string', 'in:location_log'],
            'locationLogId' => ['required', 'uuid'],
            'patrolId' => ['required', 'uuid', 'exists:patrol_sessions,id'],
            'userId' => ['required', 'uuid', 'exists:users,id'],
            'timestamp' => ['required', 'integer', 'min:0'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
            'source' => ['required', 'string', 'in:live,resume,sync'],
            'trackingState' => ['required', 'string', 'in:active,resumed,offline'],
            'speed' => ['nullable', 'numeric', 'min:0'],
            'heading' => ['nullable', 'numeric', 'between:0,360'],
        ];
    }
}
