<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCameraRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'rtsp_url' => ['required', 'url'],
            'ip_address' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'resolution_width' => ['nullable', 'integer'],
            'resolution_height' => ['nullable', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
            'last_seen_at' => ['nullable', 'date'],
        ];
    }
}
