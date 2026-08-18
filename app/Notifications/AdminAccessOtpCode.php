<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdminAccessOtpCode extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 20;

    public function __construct(
        private readonly string $code,
        private readonly int $expiresMinutes,
    ) {
        $this->onQueue('otp');
        $this->afterCommit();
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('[Receiving Operations] Admin verification code')
            ->greeting('Verify your admin access')
            ->line("Your one-time code is: {$this->code}")
            ->line("This code expires in {$this->expiresMinutes} minutes and can only be used once.")
            ->line('If you did not request this code, contact your administrator.');
    }
}
