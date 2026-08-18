<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'role' => [
                'required',
                Rule::in(['admin', 'uploader', 'warehouse_operator', 'driver']),
                Rule::exists('roles', 'name')->where('guard_name', 'web'),
            ],
            'status' => ['required', Rule::enum(UserStatus::class)],
        ];
    }
}
