<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCameraRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'rtsp_url' => ['sometimes', 'required', 'url'],
            'ip_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'port' => ['sometimes', 'nullable', 'integer'],
            'username' => ['sometimes', 'nullable', 'string', 'max:255'],
            'password' => ['sometimes', 'nullable', 'string', 'max:255'],
            'latitude' => ['sometimes', 'nullable', 'numeric'],
            'longitude' => ['sometimes', 'nullable', 'numeric'],
            'resolution_width' => ['sometimes', 'nullable', 'integer'],
            'resolution_height' => ['sometimes', 'nullable', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
            'last_seen_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
