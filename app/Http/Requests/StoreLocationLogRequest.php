<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreLocationLogRequest extends FormRequest
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
            'id' => ['nullable', 'uuid', 'unique:location_logs,id'],
            'patrol_session_id' => ['required', 'uuid', 'exists:patrol_sessions,id'],
            'user_id' => ['required', 'uuid', 'exists:users,id'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['required', 'numeric', 'min:0'],
            'timestamp' => ['required', 'integer'],
            'source' => ['required', 'in:live,resume,sync'],
            'tracking_state' => ['required', 'in:active,resumed,offline'],
            'speed' => ['nullable', 'numeric', 'min:0'],
            'heading' => ['nullable', 'numeric', 'between:0,360'],
        ];
    }
}
