<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePatrolSessionRequest extends FormRequest
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
            'user_id' => ['sometimes', 'required', 'exists:users,id'],
            'zone_id' => ['sometimes', 'required', 'uuid', 'exists:zones,id'],
            'blockchain_record_id' => ['nullable', 'uuid', 'exists:blockchain_records,id'],
            'started_at' => ['sometimes', 'required', 'date'],
            'ended_at' => ['nullable', 'date', 'after_or_equal:started_at'],
            'status' => ['sometimes', 'required', 'in:active,completed,aborted'],
        ];
    }
}
