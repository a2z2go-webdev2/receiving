<?php

namespace App\Http\Requests\Receiving;

use App\Enums\UploadWorkflow;
use App\Features\Receiving\Services\ReceivingSettings;
use App\Models\UploadType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class InitiateUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $settings = app(ReceivingSettings::class);
        $maxFiles = (int) $settings->get('max_files_per_upload');
        $maxBytes = $settings->maxFileSizeKilobytes() * 1024;
        $uploadType = $this->route('uploadType');
        $isPurchaseOrder = $uploadType instanceof UploadType
            && $uploadType->workflow === UploadWorkflow::PurchaseOrder;
        $extensions = $isPurchaseOrder ? ['pdf'] : (array) $settings->get('allowed_file_types');
        $mimeTypes = $isPurchaseOrder
            ? ['application/pdf']
            : (array) config('receiving.uploads.allowed_mime_types');

        return [
            'submission_id' => ['required', 'uuid'],
            'location' => ['nullable', 'array:latitude,longitude,accuracy,captured_at'],
            'location.latitude' => ['required_with:location', 'numeric', 'between:-90,90'],
            'location.longitude' => ['required_with:location', 'numeric', 'between:-180,180'],
            'location.accuracy' => [
                'required_with:location',
                'numeric',
                'min:0',
                'max:'.(float) config('receiving.location.max_accuracy_meters', 1000),
            ],
            'location.captured_at' => ['required_with:location', 'date'],
            'files' => ['required', 'array', 'min:1', "max:{$maxFiles}"],
            'files.*.name' => ['required', 'string', 'max:255'],
            'files.*.size' => ['required', 'integer', 'min:1', "max:{$maxBytes}"],
            'files.*.content_type' => ['required', 'string', Rule::in($mimeTypes)],
            'files.*.extension' => ['required', 'string', Rule::in($extensions)],
        ];
    }

    public function messages(): array
    {
        return [
            'files.required' => 'Select at least one scanned document.',
            'files.*.extension.in' => 'This upload page only accepts the supported document file types.',
            'files.*.content_type.in' => 'A selected file reports an unsupported content type.',
            'location.required' => 'Allow location access before uploading files.',
            'location.accuracy.max' => 'Your location is not accurate enough yet. Move near a window or outdoors, then try again.',
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->filled('location')) {
                return;
            }

            $capturedAt = $this->input('location.captured_at');
            if (! is_string($capturedAt)) {
                return;
            }

            try {
                $captured = CarbonImmutable::parse($capturedAt);
            } catch (\Throwable) {
                return;
            }

            $maxAge = (int) config('receiving.location.max_age_seconds', 120);
            if ($captured->isBefore(now()->subSeconds($maxAge)) || $captured->isAfter(now()->addSeconds(10))) {
                $validator->errors()->add(
                    'location.captured_at',
                    'Your location reading is no longer current. Allow location access again and retry.',
                );
            }
        }];
    }
}
