<?php

namespace App\Features\Receiving\Services;

use App\Enums\EmailStatus;
use App\Mail\ReceivingUploadReceived;
use App\Models\ReceivingUpload;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Throwable;

class UploadNotificationSender
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function send(ReceivingUpload $upload, ?User $actor = null, string $action = 'upload_notification_sent'): bool
    {
        $upload->loadMissing('uploadType.recipients', 'files', 'extractions.file');
        $recipients = $upload->uploadType->recipients->where('is_active', true)->groupBy('type');
        $to = collect([$upload->uploader_email])
            ->merge($recipients->get('to', collect())->pluck('email'))
            ->filter(fn (mixed $email): bool => is_string($email) && $email !== '')
            ->unique(fn (string $email): string => mb_strtolower($email))
            ->values()
            ->all();
        $toKeys = array_map(mb_strtolower(...), $to);
        $cc = $recipients->get('cc', collect())->pluck('email')
            ->reject(fn (string $email): bool => in_array(mb_strtolower($email), $toKeys, true))
            ->unique(fn (string $email): string => mb_strtolower($email))
            ->values()
            ->all();
        $ccKeys = array_map(mb_strtolower(...), $cc);
        $bcc = $recipients->get('bcc', collect())->pluck('email')
            ->reject(fn (string $email): bool => in_array(mb_strtolower($email), [...$toKeys, ...$ccKeys], true))
            ->unique(fn (string $email): string => mb_strtolower($email))
            ->values()
            ->all();

        $upload->forceFill(['email_status' => EmailStatus::Sending, 'failure_reason' => null])->save();

        try {
            Mail::to($to)->cc($cc)->bcc($bcc)->send(new ReceivingUploadReceived(
                $upload,
                URL::temporarySignedRoute('receiving.notification.show', now()->addHours(24), ['upload' => $upload->getKey()]),
                (bool) app(ReceivingSettings::class)->get('email_attachments_enabled'),
            ));
            $upload->forceFill([
                'email_status' => EmailStatus::Sent,
                'notification_sent_at' => now(),
            ])->save();
            $resent = str_contains($action, 'resent');
            $this->activity->record(
                'email',
                $action,
                'success',
                $resent
                    ? 'Upload notification email was resent'.($actor ? " by {$actor->email}" : '').'.'
                    : 'Upload notification email was sent successfully.',
                $actor,
                $upload,
            );

            return true;
        } catch (Throwable $error) {
            $upload->forceFill([
                'email_status' => EmailStatus::Failed,
                'failure_reason' => $error->getMessage(),
            ])->save();
            $this->activity->record('email', $action, 'error', 'Upload notification email could not be sent.', $actor, $upload, null, $error);

            return false;
        }
    }
}
