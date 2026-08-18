<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class UpdateUploadAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User $userToUpdate */
        $userToUpdate = $this->route('user');

        if ($userToUpdate && ! $userToUpdate->hasRole('uploader')) {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'upload_type_ids' => ['present', 'array'],
            'upload_type_ids.*' => ['integer', 'exists:upload_types,id', 'distinct'],
        ];
    }
}
