<?php

namespace App\Features\Receiving\Services;

use App\Models\ActivityLog;
use App\Models\ReceivingUpload;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class ActivityLogger
{
    public function __construct(private readonly UploadSerialNumber $serials) {}

    public function record(
        string $module,
        string $action,
        string $status,
        string $message,
        ?User $actor = null,
        ?ReceivingUpload $upload = null,
        Request|string|null $requestOrIp = null,
        Throwable|string|null $error = null,
    ): ActivityLog {
        $ip = $requestOrIp instanceof Request ? $requestOrIp->ip() : $requestOrIp;
        $errorMessage = $error instanceof Throwable ? $error->getMessage() : $error;
        $message = $this->withUploadContext($message, $upload);

        return ActivityLog::query()->create([
            'receiving_upload_id' => $upload?->getKey(),
            'user_id' => $actor?->getKey(),
            'user_email' => $actor?->email,
            'role' => $actor?->getRoleNames()->first() ?? 'system',
            'module' => $module,
            'action' => $action,
            'status' => $status,
            'message' => Str::limit($message, 255, ''),
            'error_details' => $errorMessage === null ? null : $this->redact($errorMessage),
            'ip_address' => $ip,
            'created_at' => now(),
        ]);
    }

    private function withUploadContext(string $message, ?ReceivingUpload $upload): string
    {
        if ($upload === null) {
            return $message;
        }

        $upload->loadMissing('uploadType:id,name,workflow');

        return sprintf(
            '%s upload %s: %s',
            $upload->uploadType->name,
            $this->serials->label($upload),
            $message,
        );
    }

    private function redact(string $message): string
    {
        $secrets = array_filter([
            config('services.gemini.key'),
            config('filesystems.disks.r2.key'),
            config('filesystems.disks.r2.secret'),
            config('mail.mailers.smtp.password'),
        ], fn (mixed $value): bool => is_string($value) && $value !== '');

        return Str::limit(str_replace($secrets, '[REDACTED]', $message), 4000, '…');
    }
}
