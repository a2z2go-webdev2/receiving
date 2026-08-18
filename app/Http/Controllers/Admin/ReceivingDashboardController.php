<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AiStatus;
use App\Enums\EmailStatus;
use App\Enums\ReviewStatus;
use App\Enums\UserStatus;
use App\Features\Receiving\Services\UploadSerialNumber;
use App\Http\Controllers\Controller;
use App\Models\ReceivingUpload;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class ReceivingDashboardController extends Controller
{
    public function __invoke(UploadSerialNumber $serials): Response
    {
        $now = now();
        $cards = [
            'uploads_today' => ReceivingUpload::query()->whereDate('created_at', $now->toDateString())->count(),
            'uploads_month' => ReceivingUpload::query()->whereBetween('created_at', [$now->startOfMonth(), $now->endOfMonth()])->count(),
            'pending_ai' => ReceivingUpload::query()->whereIn('ai_status', [AiStatus::Pending, AiStatus::Processing])->count(),
            'failed_ai' => ReceivingUpload::query()->whereIn('ai_status', [AiStatus::Failed, AiStatus::PartialFailed])->count(),
            'pending_reviews' => ReceivingUpload::query()->whereIn('review_status', [ReviewStatus::Pending, ReviewStatus::Revision])->count(),
            'verified_reviews' => ReceivingUpload::query()->where('review_status', ReviewStatus::Verified)->count(),
            'failed_emails' => ReceivingUpload::query()->where('review_email_status', EmailStatus::Failed)->count(),
            'active_uploaders' => User::query()->role('uploader')->where('status', UserStatus::Active)->count(),
        ];
        $recentUploads = ReceivingUpload::query()
            ->with(['uploadType:id,name,workflow', 'uploader:id,name,email'])
            ->latest()
            ->limit(10)
            ->get();
        $serialNumbers = $serials->numbersFor($recentUploads);
        $recent = $recentUploads
            ->map(fn (ReceivingUpload $upload): array => [
                'id' => $upload->getKey(),
                'serial_number' => $serialNumbers[$upload->getKey()] ?? $upload->getKey(),
                'serial_prefix' => $serials->prefix($upload->uploadType),
                'upload_type' => $upload->uploadType->name,
                'uploader' => $upload->uploader_email,
                'file_count' => $upload->file_count,
                'review_email_status' => $upload->review_email_status->value,
                'ai_status' => $upload->ai_status->value,
                'review_status' => $upload->review_status->value,
                'created_at' => $upload->created_at->toISOString(),
            ]);

        return Inertia::render('admin/dashboard', ['cards' => $cards, 'recentUploads' => $recent]);
    }
}
