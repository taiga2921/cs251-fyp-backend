<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->route('user');
        $userId = is_object($user) ? $user->id : $user;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($userId)],
            'password' => ['sometimes', 'string', 'min:8'],
            'role_id' => ['sometimes', 'exists:roles,id'],
            'phone' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'profile_picture_url' => ['nullable', 'string', 'max:255'],
            'two_factor_enabled' => ['sometimes', 'boolean'],
            'two_factor_secret' => ['nullable', 'string'],
            'profile_version' => ['sometimes', 'integer'],
            'last_password_changed_at' => ['nullable', 'date'],
            'email_verified_at' => ['nullable', 'date'],
        ];
    }
}
