<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCheckpointRequest extends FormRequest
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
            'zone_id' => ['required', 'uuid', 'exists:zones,id'],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('checkpoints', 'name')
                    ->where(fn ($query) => $query->where('zone_id', $this->input('zone_id'))),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius' => ['required', 'numeric', 'min:5', 'max:100'],
            'location_type' => ['required', 'in:outdoor,indoor'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
