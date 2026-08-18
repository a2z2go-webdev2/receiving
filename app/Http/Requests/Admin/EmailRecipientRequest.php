<?php

namespace App\Http\Requests\Admin;

use App\Enums\UploadWorkflow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmailRecipientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'upload_type_id' => [
                'required',
                'integer',
                Rule::exists('upload_types', 'id')->where('workflow', UploadWorkflow::Standard->value),
            ],
            'email' => [
                'required',
                'email:rfc',
                'max:255',
                Rule::unique('email_recipients', 'email')
                    ->where(fn ($query) => $query
                        ->where('upload_type_id', $this->integer('upload_type_id'))
                        ->where('type', $this->string('type')->toString()))
                    ->ignore($this->route('recipient')),
            ],
            'type' => ['required', Rule::in(['to', 'cc', 'bcc'])],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
