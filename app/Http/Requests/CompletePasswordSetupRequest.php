<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CompletePasswordSetupRequest extends FormRequest
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
        $minLength = (int) config('auth_security.password_min_length', 12);

        return [
            'setup_token' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', 'min:'.$minLength],
        ];
    }
}
