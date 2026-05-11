<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePatrolRouteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        /** Legacy alias: same UUID as patrol_sessions.id; canonical field is patrol_session_id. */
        if (! $this->filled('patrol_session_id') && $this->filled('patrol_log_id')) {
            $this->merge([
                'patrol_session_id' => $this->input('patrol_log_id'),
            ]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'patrol_session_id' => ['required', 'uuid', 'exists:patrol_sessions,id'],
            'patrol_log_id' => ['sometimes', 'nullable', 'uuid'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
            'altitude' => ['nullable', 'numeric'],
            'recorded_at' => ['nullable', 'date'],
            'timestamp' => ['nullable', 'integer', 'min:0'],
            'guard_id' => ['nullable', 'uuid', 'exists:users,id'],
        ];
    }
}
