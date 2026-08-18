<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReceivingSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'otp_expiration_minutes' => ['required', 'integer', 'between:1,30'],
            'review_link_expiration_hours' => ['required', 'integer', 'between:1,168'],
            'max_file_size_kilobytes' => ['required', 'integer', 'between:100,51200'],
            'max_files_per_upload' => ['required', 'integer', 'between:1,50'],
            'allowed_file_types' => ['required', 'array', 'min:1'],
            'allowed_file_types.*' => ['string', Rule::in(['jpg', 'jpeg', 'png', 'pdf'])],
            'compression_enabled' => ['required', 'boolean'],
            'max_image_width' => ['required', 'integer', 'between:800,10000'],
            'max_image_height' => ['required', 'integer', 'between:800,10000'],
            'jpeg_quality' => ['required', 'integer', 'between:60,95'],
            'ai_batch_size' => ['required', 'integer', 'between:1,10'],
            'ai_retry_limit' => ['required', 'integer', 'between:1,10'],
            'ai_retry_backoff_seconds' => ['required', 'integer', 'between:5,3600'],
            'email_attachments_enabled' => ['required', 'boolean'],
            'review_recipient_rule' => ['required', Rule::in(['uploader', 'upload_recipients'])],
            'staging_cleanup_hours' => ['required', 'integer', 'between:1,168'],
            'signed_url_expiration_minutes' => ['required', 'integer', 'between:5,120'],
        ];
    }
}
