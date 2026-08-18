<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(6)],
            'role' => [
                'required',
                Rule::in(['admin', 'uploader', 'warehouse_operator', 'driver']),
                Rule::exists('roles', 'name')->where('guard_name', 'web'),
            ],
            'status' => ['required', Rule::enum(UserStatus::class)],
        ];
    }
}
