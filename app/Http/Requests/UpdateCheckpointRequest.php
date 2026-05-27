<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCheckpointRequest extends FormRequest
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
        $checkpoint = $this->route('checkpoint');
        $checkpointId = is_object($checkpoint) ? $checkpoint->id : $checkpoint;
        $zoneId = $this->input('zone_id', is_object($checkpoint) ? $checkpoint->zone_id : null);

        return [
            'zone_id' => ['sometimes', 'required', 'uuid', 'exists:zones,id'],
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('checkpoints', 'name')
                    ->where(fn ($query) => $query->where('zone_id', $zoneId))
                    ->ignore($checkpointId),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'latitude' => ['sometimes', 'required', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'required', 'numeric', 'between:-180,180'],
            'radius' => ['sometimes', 'required', 'numeric', 'min:5', 'max:100'],
            'location_type' => ['sometimes', 'required', Rule::in(['outdoor', 'indoor'])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
