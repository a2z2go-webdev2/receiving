<?php

namespace App\Features\Receiving\Services;

use App\Enums\EmailStatus;
use App\Mail\ReceivingReviewReady;
use App\Models\ReceivingUpload;
use App\Models\ReviewLink;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class ReviewLinkService
{
    public function __construct(
        private readonly ReceivingSettings $settings,
        private readonly ActivityLogger $activity,
    ) {}

    public function issueAndSend(ReceivingUpload $upload): bool
    {
        $upload->loadMissing('uploadType.recipients');
        $emails = $this->reviewEmails($upload);
        $sent = 0;
        $upload->forceFill([
            'review_email_status' => EmailStatus::Sending,
            'review_email_failure_reason' => null,
        ])->save();

        foreach ($emails as $email) {
            $token = Str::random(64);
            ReviewLink::query()
                ->where('receiving_upload_id', $upload->getKey())
                ->where('email', $email)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            ReviewLink::query()->create([
                'receiving_upload_id' => $upload->getKey(),
                'email' => $email,
                'upload_type_id' => $upload->upload_type_id,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addHours((int) $this->settings->get('review_link_expiration_hours')),
            ]);

            try {
                Mail::to($email)->send(new ReceivingReviewReady(
                    $upload,
                    route('receiving.review.show', ['token' => $token]),
                ));
                $sent++;
                $this->activity->record('email', 'review_notification_sent', 'success', "Review email was sent to {$email}.", null, $upload);
            } catch (Throwable $error) {
                $this->activity->record('email', 'review_notification_failed', 'error', "Review email could not be sent to {$email}.", null, $upload, null, $error);
            }
        }

        $delivered = $sent > 0;
        $upload->forceFill([
            'review_email_status' => $delivered ? EmailStatus::Sent : EmailStatus::Failed,
            'review_email_failure_reason' => $delivered ? null : 'Review email could not be delivered to any recipient.',
            'review_notification_sent_at' => $delivered ? now() : null,
        ])->save();

        return $delivered;
    }

    public function resolve(string $token): ?ReviewLink
    {
        return ReviewLink::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();
    }

    /** @return array<int, string> */
    private function reviewEmails(ReceivingUpload $upload): array
    {
        if ($this->settings->get('review_recipient_rule') === 'upload_recipients') {
            $emails = $upload->uploadType->recipients
                ->where('is_active', true)
                ->pluck('email')
                ->unique()
                ->values()
                ->all();

            return $emails === [] ? [$upload->uploader_email] : $emails;
        }

        return [$upload->uploader_email];
    }
}
