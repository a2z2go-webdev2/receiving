<?php

namespace App\Mail;

use App\Models\ReceivingUpload;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReceivingReviewReady extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly ReceivingUpload $upload,
        public readonly string $reviewUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "[{$this->upload->uploadType->name}] Review receiving upload SN-{$this->upload->getKey()}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.receiving.review-ready');
    }
}
