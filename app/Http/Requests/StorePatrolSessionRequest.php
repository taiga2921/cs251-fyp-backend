<?php

namespace App\Http\Requests;

use Carbon\Carbon;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePatrolSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('started_at')) {
            $merge['started_at'] = Carbon::parse($this->input('started_at'))->utc()->toIso8601String();
        }

        if ($this->has('ended_at') && $this->input('ended_at') !== null) {
            $merge['ended_at'] = Carbon::parse($this->input('ended_at'))->utc()->toIso8601String();
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'exists:users,id'],
            'zone_id' => ['required', 'uuid', 'exists:zones,id'],
            'blockchain_record_id' => ['nullable', 'uuid', 'exists:blockchain_records,id'],
            'started_at' => ['required', 'date'],
            'ended_at' => ['nullable', 'date', 'after_or_equal:started_at'],
            'status' => ['nullable', 'in:active,completed,aborted'],
        ];
    }
}
