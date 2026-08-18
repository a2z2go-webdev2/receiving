<?php

use App\Enums\AiStatus;
use App\Enums\EmailStatus;
use App\Features\Receiving\Services\ReviewLinkService;
use App\Features\Receiving\Services\UploadNotificationSender;
use App\Mail\ReceivingReviewReady;
use App\Mail\ReceivingUploadReceived;
use App\Models\AiExtraction;
use App\Models\EmailRecipient;
use App\Models\ReceivingUpload;
use App\Models\UploadedFile;
use App\Models\UploadType;
use App\Models\User;
use Database\Seeders\UploadTypeSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(fn () => $this->seed(UploadTypeSeeder::class));

it('delivers one upload notification with configured to cc and bcc recipients', function (): void {
    Mail::fake();
    $type = UploadType::query()->firstOrFail();
    foreach ([['to@example.com', 'to'], ['cc@example.com', 'cc'], ['bcc@example.com', 'bcc']] as [$email, $kind]) {
        EmailRecipient::query()->create(['upload_type_id' => $type->getKey(), 'email' => $email, 'type' => $kind, 'is_active' => true]);
    }
    $upload = notificationUpload($type);

    expect(app(UploadNotificationSender::class)->send($upload))->toBeTrue()
        ->and($upload->refresh()->email_status)->toBe(EmailStatus::Sent);
    Mail::assertSent(ReceivingUploadReceived::class, fn (ReceivingUploadReceived $mail): bool => $mail->hasTo($upload->uploader_email) && $mail->hasTo('to@example.com') && $mail->hasCc('cc@example.com') && $mail->hasBcc('bcc@example.com')
    );
});

it('always delivers the upload notification to the uploader without a configured primary recipient', function (): void {
    Mail::fake();
    $type = UploadType::query()->firstOrFail();
    EmailRecipient::query()->create(['upload_type_id' => $type->getKey(), 'email' => 'cc@example.com', 'type' => 'cc', 'is_active' => true]);
    $upload = notificationUpload($type);

    expect(app(UploadNotificationSender::class)->send($upload))->toBeTrue()
        ->and($upload->refresh()->email_status)->toBe(EmailStatus::Sent);
    Mail::assertSent(
        ReceivingUploadReceived::class,
        fn (ReceivingUploadReceived $mail): bool => $mail->hasTo($upload->uploader_email)
            && $mail->hasCc('cc@example.com'),
    );
});

it('persists a retryable failed status when the mail transport fails', function (): void {
    $type = UploadType::query()->firstOrFail();
    EmailRecipient::query()->create(['upload_type_id' => $type->getKey(), 'email' => 'to@example.com', 'type' => 'to', 'is_active' => true]);
    $upload = notificationUpload($type);
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP connection timed out.'));

    expect(app(UploadNotificationSender::class)->send($upload))->toBeFalse()
        ->and($upload->refresh()->email_status)->toBe(EmailStatus::Failed)
        ->and($upload->failure_reason)->toContain('SMTP connection timed out');
});

it('does not duplicate the uploader across to and cc headers', function (): void {
    Mail::fake();
    $type = UploadType::query()->firstOrFail();
    $upload = notificationUpload($type);
    EmailRecipient::query()->create([
        'upload_type_id' => $type->getKey(),
        'email' => $upload->uploader_email,
        'type' => 'cc',
        'is_active' => true,
    ]);

    expect(app(UploadNotificationSender::class)->send($upload))->toBeTrue();
    Mail::assertSent(
        ReceivingUploadReceived::class,
        fn (ReceivingUploadReceived $mail): bool => $mail->hasTo($upload->uploader_email)
            && ! $mail->hasCc($upload->uploader_email),
    );
});

it('persists a failed review email status when delivery fails', function (): void {
    $type = UploadType::query()->firstOrFail();
    $upload = notificationUpload($type);
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('Review SMTP unavailable.'));

    expect(app(ReviewLinkService::class)->issueAndSend($upload))->toBeFalse()
        ->and($upload->refresh()->review_email_status)->toBe(EmailStatus::Failed)
        ->and($upload->review_email_failure_reason)->toContain('could not be delivered');
});

it('does not render the view full transaction link in the email body', function (): void {
    $type = UploadType::query()->firstOrFail();
    $upload = notificationUpload($type);
    $mail = new ReceivingUploadReceived($upload, 'https://example.test/transaction');
    $upload->load(['uploadType', 'files', 'extractions.file']);

    $html = $mail->render();
    $text = view('mail.receiving.upload-received-text', [
        'upload' => $upload,
        'transactionUrl' => 'https://example.test/transaction',
        'emailBranding' => $mail->emailBranding(),
        'emailRows' => $mail->emailRows(),
        'quality' => $mail->calculateQualityPercentage(),
    ])->render();

    expect($html)
        ->not->toContain('View the full transaction')
        ->not->toContain('https://example.test/transaction')
        ->not->toContain('secure link')
        ->not->toContain('expires in 24 hours');

    expect($text)
        ->not->toContain('View the full transaction')
        ->not->toContain('https://example.test/transaction');
});

it('builds the expected subject and renders the header table contact and feedback image', function (): void {
    $type = UploadType::query()->where('slug', 'a2z2go')->firstOrFail();
    $upload = notificationUpload($type);
    $mail = new ReceivingUploadReceived($upload, 'https://example.test/transaction');

    $rendered = $mail->render();

    expect($mail->envelope()->subject)
        ->toBe(sprintf('Receive %s sn=%d %s', $type->name, $upload->getKey(), $upload->created_at->format('Y-m-d H:i')))
        ->and($rendered)->toContain('Receiving Upload Notification')
        ->and($rendered)->toContain('Serial Number:')
        ->and($rendered)->toContain(ReceivingUploadReceived::FINANCE_CONTACT_NAME)
        ->and($rendered)->toContain(ReceivingUploadReceived::FINANCE_CONTACT_EMAIL)
        ->and($rendered)->toContain(ReceivingUploadReceived::FEEDBACK_IMAGE_URL)
        ->and($rendered)->toContain(ReceivingUploadReceived::FEEDBACK_FORM_URL);
});

it('uses the pingcon feedback and finance details for pingcon and bonita', function (string $slug): void {
    $type = UploadType::query()->where('slug', $slug)->firstOrFail();
    $mail = new ReceivingUploadReceived(notificationUpload($type), 'https://example.test/transaction');

    expect($mail->render())
        ->toContain(ReceivingUploadReceived::PINGCON_FINANCE_CONTACT_EMAIL)
        ->toContain(ReceivingUploadReceived::PINGCON_FEEDBACK_IMAGE_URL)
        ->toContain(ReceivingUploadReceived::PINGCON_FEEDBACK_FORM_URL)
        ->not->toContain(ReceivingUploadReceived::FINANCE_CONTACT_EMAIL);
})->with(['pingcon', 'bonita']);

it('identifies the upload type in review email subjects', function (): void {
    $type = UploadType::query()->where('slug', 'bonita')->firstOrFail();
    $upload = notificationUpload($type);

    expect((new ReceivingReviewReady($upload, 'https://example.test/review'))->envelope()->subject)
        ->toBe("[BONITA] Review receiving upload SN-{$upload->getKey()}");
});

it('renders the supplier columns horizontally and omits line items', function (): void {
    $type = UploadType::query()->firstOrFail();
    $upload = notificationUpload($type);
    $file = UploadedFile::query()->create([
        'receiving_upload_id' => $upload->getKey(), 'original_file_name' => 'invoice.pdf',
        'sanitized_file_name' => 'invoice.pdf', 'stored_file_name' => 'invoice.pdf',
        'file_extension' => 'pdf', 'r2_bucket' => 'test', 'r2_object_key' => 'receiving/invoice.pdf',
        'r2_staging_object_key' => 'staging/invoice.pdf', 'original_file_size' => 100,
        'final_file_size' => 100, 'declared_content_type' => 'application/pdf',
        'content_type' => 'application/pdf', 'ai_status' => AiStatus::Extracted,
    ]);
    $data = [
        'document_type' => 'Invoice',
        'fields' => [
            ['label' => 'Company Name', 'value' => 'Readable Supplier'],
            ['label' => 'Address', 'value' => '12 Sample St., Quezon City'],
            ['label' => 'TIN', 'value' => '200-833-967-00000'],
            ['label' => 'Invoice Number', 'value' => 'INV-2026-0042'],
            ['label' => 'PO Number', 'value' => 'PO-2026-0099'],
            ['label' => 'Invoice Date', 'value' => '2026-07-05'],
            ['label' => 'Gross', 'value' => '5,600.00'],
        ],
        'items' => [
            ['description' => 'Delivery service', 'amount' => '500.00'],
            ['description' => 'Consulting', 'amount' => '5,100.00'],
        ],
    ];
    AiExtraction::query()->create([
        'receiving_upload_id' => $upload->getKey(), 'uploaded_file_id' => $file->getKey(),
        'document_type' => 'Invoice', 'raw_extracted_json' => $data, 'corrected_json' => $data,
        'ai_status' => AiStatus::Extracted,
    ]);
    $upload->load(['uploadType', 'files', 'extractions.file']);

    $rendered = (new ReceivingUploadReceived($upload, 'https://example.test/transaction'))->render();

    expect($rendered)
        ->toContain('Company Name')
        ->toContain('Address')
        ->toContain('TIN')
        ->toContain('Invoice Number')
        ->toContain('PO Number')
        ->toContain('Readable Supplier')
        ->toContain('INV-2026-0042')
        ->toContain('PO-2026-0099')
        ->toContain('Invoice Date')
        ->toContain('2026-07-05')
        ->not->toContain('Delivery service')
        ->not->toContain('Consulting')
        ->not->toContain('Gross')
        ->not->toContain('5,600.00')
        ->not->toContain('invoice.pdf')
        ->not->toContain('Array');

    // Headers must sit in <thead> (i.e. horizontal across the top).
    preg_match('/<thead\b.*?>(.*?)<\/thead>/s', $rendered, $headerBlock);
    expect($headerBlock[1] ?? '')
        ->toContain('Company Name')
        ->toContain('Address')
        ->toContain('TIN')
        ->toContain('Invoice Number')
        ->toContain('PO Number')
        ->toContain('Invoice Date')
        ->toContain('PO Date')
        ->toContain('Waiting Time');

    // None of the supplier labels should appear in a left-column <th scope="row"> cell.
    expect($rendered)->not->toContain('scope="row"');

    // The table must live inside a horizontally scrollable wrapper for narrow viewports.
    expect($rendered)
        ->toContain('overflow-x:auto')
        ->toContain('-webkit-overflow-scrolling:touch')
        ->toContain('min-width:480px');
});

it('falls back to the raw extracted json when the review correction has not been saved', function (): void {
    $type = UploadType::query()->firstOrFail();
    $upload = notificationUpload($type);
    $file = UploadedFile::query()->create([
        'receiving_upload_id' => $upload->getKey(), 'original_file_name' => 'invoice.pdf',
        'sanitized_file_name' => 'invoice.pdf', 'stored_file_name' => 'invoice.pdf',
        'file_extension' => 'pdf', 'r2_bucket' => 'test', 'r2_object_key' => 'receiving/invoice.pdf',
        'r2_staging_object_key' => 'staging/invoice.pdf', 'original_file_size' => 100,
        'final_file_size' => 100, 'declared_content_type' => 'application/pdf',
        'content_type' => 'application/pdf', 'ai_status' => AiStatus::Extracted,
    ]);
    $rawData = [
        'document_type' => 'Invoice',
        'fields' => [
            ['label' => 'Company Name', 'value' => 'Raw AI Supplier'],
            ['label' => 'TIN', 'value' => '123-456-789-000'],
        ],
        'items' => [],
    ];
    AiExtraction::query()->create([
        'receiving_upload_id' => $upload->getKey(), 'uploaded_file_id' => $file->getKey(),
        'document_type' => 'Invoice', 'raw_extracted_json' => $rawData, 'corrected_json' => null,
        'ai_status' => AiStatus::Extracted,
    ]);
    $upload->load(['uploadType', 'files', 'extractions.file']);

    $rendered = (new ReceivingUploadReceived($upload, 'https://example.test/transaction'))->render();

    expect($rendered)->toContain('Raw AI Supplier')
        ->toContain('123-456-789-000')
        ->not->toContain('No AI extraction data is available yet for this upload.');
});

it('computes waiting time from the po date and upload completion instead of rendering the ai placeholder', function (): void {
    $type = UploadType::query()->where('slug', 'a2z2go')->firstOrFail();
    $upload = notificationUpload($type);
    $upload->forceFill(['upload_completed_at' => '2026-07-12 09:00:00'])->save();
    $file = UploadedFile::query()->create([
        'receiving_upload_id' => $upload->getKey(), 'original_file_name' => 'waiting.pdf',
        'sanitized_file_name' => 'waiting.pdf', 'stored_file_name' => 'waiting.pdf',
        'file_extension' => 'pdf', 'r2_bucket' => 'test', 'r2_object_key' => 'receiving/waiting.pdf',
        'r2_staging_object_key' => 'staging/waiting.pdf', 'original_file_size' => 100,
        'final_file_size' => 100, 'declared_content_type' => 'application/pdf',
        'content_type' => 'application/pdf', 'ai_status' => AiStatus::Extracted,
    ]);
    AiExtraction::query()->create([
        'receiving_upload_id' => $upload->getKey(), 'uploaded_file_id' => $file->getKey(),
        'document_type' => 'Delivery Receipt',
        'raw_extracted_json' => [
            'document_type' => 'Delivery Receipt',
            'fields' => [
                ['label' => 'PO Number', 'value' => '28338'],
                ['label' => 'PO Date', 'value' => 'July 06, 2026'],
                ['label' => 'Waiting Time', 'value' => '[See image]'],
            ],
            'items' => [],
        ],
        'corrected_json' => null,
        'ai_status' => AiStatus::Extracted,
    ]);
    $upload->load(['uploadType', 'files', 'extractions.file']);
    $mail = new ReceivingUploadReceived($upload, 'https://example.test/transaction');

    $rendered = $mail->render();
    $text = view('mail.receiving.upload-received-text', [
        'upload' => $upload,
        'emailBranding' => $mail->emailBranding(),
        'emailRows' => $mail->emailRows(),
        'quality' => $mail->calculateQualityPercentage(),
    ])->render();

    expect($rendered)->toContain('6 days')->not->toContain('[See image]')
        ->and($text)->toContain('Waiting Time: 6 days')->not->toContain('[See image]');
});

function notificationUpload(UploadType $type): ReceivingUpload
{
    $user = User::factory()->create();

    return ReceivingUpload::query()->create([
        'submission_id' => fake()->uuid(), 'upload_type_id' => $type->getKey(),
        'uploader_user_id' => $user->getKey(), 'uploader_email' => $user->email,
        'r2_bucket' => 'test', 'r2_prefix' => 'receiving/test', 'file_count' => 1,
    ]);
}
