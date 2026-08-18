<?php

namespace App\Http\Controllers\Receiving;

use App\Enums\ReviewStatus;
use App\Enums\UploadWorkflow;
use App\Features\Receiving\Services\ActivityLogger;
use App\Features\Receiving\Services\InvoiceReviewValidator;
use App\Features\Receiving\Services\OtpDeviceRemember;
use App\Features\Receiving\Services\PurchaseOrderLinker;
use App\Features\Receiving\Services\ReceivingSettings;
use App\Features\Receiving\Services\UploadOtpGrant;
use App\Features\Receiving\Services\UploadOtpService;
use App\Features\Receiving\Services\UploadSerialNumber;
use App\Http\Controllers\Controller;
use App\Http\Requests\Receiving\VerifyReceivingReviewRequest;
use App\Http\Requests\Receiving\VerifyUploadOtpRequest;
use App\Models\ReceivingUpload;
use App\Models\UploadedFile;
use App\Models\UploadType;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class UploadPageController extends Controller
{
    public function show(
        Request $request,
        UploadType $uploadType,
        UploadOtpGrant $grant,
        UploadOtpService $otp,
        ReceivingSettings $settings,
        ActivityLogger $activity,
        OtpDeviceRemember $remember,
    ): Response|RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        if (! $uploadType->is_active) {
            return Inertia::render('upload/unavailable', [
                'uploadType' => $uploadType->only(['name', 'slug']),
            ]);
        }

        if (! $user->canAccessUploadType($uploadType)) {
            return redirect()->route('dashboard');
        }

        if (! $grant->refresh($request, $uploadType)) {
            // Auto-grant when a valid remember-device cookie is present.
            if ($remember->check($request, "upload_{$uploadType->getKey()}", $user)) {
                $grant->grant($request, $uploadType);
            } else {
                if ($grant->expired($request, $uploadType)) {
                    $activity->record('auth', 'upload_session_expired', 'warning', "{$user->email}'s {$uploadType->name} upload session expired.", $user, null, $request);
                    $grant->revoke($request, $uploadType);
                    $request->session()->flash(
                        'session_expired',
                        "Your {$uploadType->name} upload verification expired. Enter a new email code to continue.",
                    );
                }

                if (! $otp->hasLiveCode($user, $uploadType)) {
                    $otp->issue($user, $uploadType, $request->ip());
                }

                return Inertia::render('upload/otp', [
                    'uploadType' => $uploadType->only(['name', 'slug']),
                    'maskedEmail' => $this->maskEmail($user->email),
                    'expiresMinutes' => (int) $settings->get('otp_expiration_minutes'),
                ]);
            }
        }

        return Inertia::render('upload/show', [
            'uploadType' => [
                ...$uploadType->only(['id', 'name', 'slug']),
                'workflow' => $uploadType->workflow->value,
            ],
            'constraints' => [
                'maxFiles' => (int) $settings->get('max_files_per_upload'),
                'maxFileKilobytes' => $settings->maxFileSizeKilobytes(),
                'allowedExtensions' => $uploadType->workflow === UploadWorkflow::PurchaseOrder
                    ? ['pdf']
                    : (array) $settings->get('allowed_file_types'),
                'maxLocationAccuracyMeters' => (float) config('receiving.location.max_accuracy_meters', 1000),
            ],
        ]);
    }

    public function history(
        Request $request,
        UploadType $uploadType,
        UploadSerialNumber $serials,
    ): Response|RedirectResponse {
        if (! $uploadType->is_active) {
            return redirect()->route('receiving.upload.show', $uploadType);
        }

        $user = $request->user();
        abort_unless($user instanceof User, 403);
        if (! $user->canAccessUploadType($uploadType)) {
            return redirect()->route('dashboard');
        }

        /** @var LengthAwarePaginator<int, ReceivingUpload> $uploads */
        $uploads = $user->receivingUploads()
            ->where('upload_type_id', $uploadType->getKey())
            ->where('processing_status', '!=', 'staging')
            ->latest()
            ->paginate(20)
            ->withQueryString();
        $serialNumbers = $serials->numbersFor($uploads->getCollection());
        $uploads->through(fn (ReceivingUpload $upload): array => [
            'id' => $upload->getKey(),
            'serial_number' => $serialNumbers[$upload->getKey()] ?? $upload->getKey(),
            'serial_prefix' => $serials->prefix($uploadType),
            'created_at' => $upload->created_at->toISOString(),
            'file_count' => $upload->file_count,
            'review_email_status' => $upload->review_email_status->value,
            'ai_status' => $upload->ai_status->value,
            'review_status' => $upload->review_status->value,
        ]);

        return Inertia::render('upload/history', [
            'uploadType' => [
                ...$uploadType->only(['id', 'name', 'slug']),
                'requires_review' => $uploadType->workflow->requiresReview(),
                'serial_prefix' => $serials->prefix($uploadType),
            ],
            'uploads' => $uploads,
        ]);
    }

    public function verify(
        VerifyUploadOtpRequest $request,
        UploadType $uploadType,
        UploadOtpService $otp,
        UploadOtpGrant $grant,
        OtpDeviceRemember $remember,
    ): RedirectResponse {
        if (! $uploadType->is_active) {
            return redirect()->route('receiving.upload.show', $uploadType);
        }

        $user = $request->user();
        abort_unless($user instanceof User, 403);
        if (! $user->canAccessUploadType($uploadType)) {
            return redirect()->route('dashboard');
        }

        if (! $otp->verify($user, $uploadType, $request->string('code')->toString(), $request->ip())) {
            return back()->withErrors(['code' => 'The OTP is incorrect or has expired.']);
        }

        $grant->grant($request, $uploadType);

        $response = redirect()->route('receiving.upload.show', $uploadType);

        if ($request->boolean('remember')) {
            $response->withCookie($remember->cookie("upload_{$uploadType->getKey()}", $user));
        }

        return $response;
    }

    public function resend(Request $request, UploadType $uploadType, UploadOtpService $otp): RedirectResponse
    {
        if (! $uploadType->is_active) {
            return redirect()->route('receiving.upload.show', $uploadType);
        }

        $user = $request->user();
        abort_unless($user instanceof User, 403);
        if (! $user->canAccessUploadType($uploadType)) {
            return redirect()->route('dashboard');
        }
        $otp->issue($user, $uploadType, $request->ip(), true);

        return back()->with('status', 'A new verification code was sent.');
    }

    public function editVerified(
        Request $request,
        UploadType $uploadType,
        ReceivingUpload $upload,
        UploadSerialNumber $serials,
        InvoiceReviewValidator $validator,
        ReceivingSettings $settings,
    ): Response|RedirectResponse {
        if (! $uploadType->is_active) {
            return redirect()->route('receiving.upload.show', $uploadType);
        }

        $user = $request->user();
        abort_unless($user instanceof User, 403);
        if (! $user->canAccessUploadType($uploadType)) {
            return redirect()->route('dashboard');
        }

        abort_unless(
            $upload->upload_type_id === $uploadType->getKey()
                && $upload->uploader_user_id === $user->getKey()
                && $upload->review_status === ReviewStatus::Verified,
            403
        );

        $upload->load(['uploadType', 'files.extraction']);
        $serialNumber = $serials->number($upload);
        $prefix = $serials->prefix($uploadType);

        return Inertia::render('upload/edit-verified', [
            'uploadType' => [
                ...$uploadType->only(['id', 'name', 'slug']),
                'serial_prefix' => $prefix,
            ],
            'upload' => [
                'id' => $upload->getKey(),
                'serial_number' => $serialNumber,
                'serial_prefix' => $prefix,
                'upload_type' => $upload->uploadType->name,
                'uploader_email' => $upload->uploader_email,
                'created_at' => $upload->created_at->toISOString(),
                'review_status' => $upload->review_status->value,
                'files' => $upload->files->whereNotNull('extraction')->map(fn (UploadedFile $file): array => [
                    'id' => $file->getKey(),
                    'name' => $file->original_file_name,
                    'content_type' => $file->content_type,
                    'preview_url' => $this->uploaderFilePreviewUrl($uploadType, $file, $settings),
                    'extraction' => [
                        'id' => $file->extraction->getKey(),
                        'document_type' => $file->extraction->document_type,
                        'corrected_data' => $validator->normalize(
                            (array) ($file->extraction->corrected_json ?? $file->extraction->raw_extracted_json),
                            false,
                            $file->extraction
                        ),
                        'review_status' => $file->extraction->review_status->value,
                    ],
                ])->values(),
            ],
        ]);
    }

    public function updateVerified(
        VerifyReceivingReviewRequest $request,
        UploadType $uploadType,
        ReceivingUpload $upload,
        InvoiceReviewValidator $validator,
        PurchaseOrderLinker $purchaseOrderLinks,
        ActivityLogger $activity,
    ): RedirectResponse {
        if (! $uploadType->is_active) {
            return redirect()->route('receiving.upload.show', $uploadType);
        }

        $user = $request->user();
        abort_unless($user instanceof User, 403);
        if (! $user->canAccessUploadType($uploadType)) {
            return redirect()->route('dashboard');
        }

        abort_unless(
            $upload->upload_type_id === $uploadType->getKey()
                && $upload->uploader_user_id === $user->getKey()
                && $upload->review_status === ReviewStatus::Verified,
            403
        );

        $upload->load('extractions.file');
        abort_if($upload->extractions->isEmpty(), 422, 'There is no extracted data to edit.');

        /** @var array<int|string, array<string, mixed>> $submittedCorrections */
        $submittedCorrections = $request->validated('corrected_data', []);
        if ($submittedCorrections !== []) {
            $expectedIds = $upload->extractions
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
            $submittedIds = array_map(
                fn (int|string $id): int => is_numeric($id) ? (int) $id : 0,
                array_keys($submittedCorrections),
            );
            if (array_diff($expectedIds, $submittedIds) !== []
                || array_diff($submittedIds, $expectedIds) !== []) {
                throw ValidationException::withMessages([
                    'corrected_data' => 'Corrected data must be included for every scanned file before saving.',
                ]);
            }
        }

        DB::transaction(function () use ($upload, $user, $validator, $purchaseOrderLinks, $submittedCorrections): void {
            foreach ($upload->extractions as $extraction) {
                $corrected = $submittedCorrections === []
                    ? (array) $extraction->corrected_json
                    : $submittedCorrections[$extraction->getKey()];
                $corrected = $validator->normalize($corrected, true);
                $extraction->forceFill([
                    'document_type' => $corrected['document_type'],
                    'corrected_json' => $corrected,
                    'review_status' => ReviewStatus::Verified,
                    'reviewed_at' => now(),
                    'reviewed_by_email' => $user->email,
                ])->save();
                $extraction->file->forceFill(['review_status' => ReviewStatus::Verified])->save();
                $purchaseOrderLinks->syncExtraction($extraction);
            }

            $upload->forceFill(['review_status' => ReviewStatus::Verified])->save();
        });

        $activity->record('review', 'scanned_data_corrected_by_uploader', 'success', "Verified scanned data was updated by uploader {$user->email}.", $user, $upload, $request);

        return redirect()->route('receiving.upload.history', $uploadType)->with('status', 'Corrected data updated successfully.');
    }

    public function filePreview(Request $request, UploadType $uploadType, UploadedFile $file): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        abort_unless($user->canAccessUploadType($uploadType), 403);
        $file->loadMissing('upload');
        abort_unless($file->upload->upload_type_id === $uploadType->getKey(), 403);
        abort_unless($file->upload->uploader_user_id === $user->getKey(), 403);
        abort_if($file->r2_object_key === null, 404);

        return Storage::disk((string) config('receiving.disk'))->response(
            $file->r2_object_key,
            $file->sanitized_file_name,
            ['Content-Type' => $file->content_type, 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    private function uploaderFilePreviewUrl(UploadType $uploadType, UploadedFile $file, ReceivingSettings $settings): string
    {
        try {
            return Storage::disk((string) config('receiving.disk'))->temporaryUrl(
                (string) $file->r2_object_key,
                now()->addMinutes((int) $settings->get('signed_url_expiration_minutes')),
            );
        } catch (Throwable) {
            return route('receiving.upload.files.preview', ['uploadType' => $uploadType->slug, 'file' => $file->getKey()]);
        }
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);

        return substr($local, 0, 1).str_repeat('•', max(2, strlen($local) - 1)).'@'.$domain;
    }
}
