<?php

namespace App\Http\Requests;

use App\Models\Zone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateZoneRequest extends FormRequest
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
        /** @var Zone $zone */
        $zone = $this->route('zone');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('zones', 'name')->ignore($zone->getKey()),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'created_by' => ['nullable', 'exists:users,id'],
        ];
    }
}
