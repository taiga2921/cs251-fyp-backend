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
        $minLength = (int) config('auth_security.password_min_length', 12);

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($userId)],
            'password' => ['sometimes', 'string', 'min:'.$minLength],
            'role_id' => ['sometimes', 'exists:roles,id'],
            'phone' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'profile_picture_url' => ['nullable', 'string', 'max:255'],
            'profile_version' => ['sometimes', 'integer'],
            'email_verified_at' => ['nullable', 'date'],
            'setup_required' => ['prohibited'],
            'two_factor_enabled' => ['prohibited'],
            'two_factor_secret' => ['prohibited'],
            'last_password_changed_at' => ['prohibited'],
        ];
    }
}
