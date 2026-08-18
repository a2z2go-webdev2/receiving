<?php

namespace App\Mail;

use App\Features\Receiving\Services\ImageToPdfConverter;
use App\Features\Receiving\Services\PurchaseOrderDataNormalizer;
use App\Models\AiExtraction;
use App\Models\PoExtraction;
use App\Models\ReceivingUpload;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ReceivingUploadReceived extends Mailable
{
    use Queueable, SerializesModels;

    public const FINANCE_CONTACT_NAME = 'Bats Bernarte';

    public const FINANCE_CONTACT_EMAIL = 'finance@A2Z2GO.net';

    public const FEEDBACK_IMAGE_URL = 'https://jelmir13.x02.me/i/a2z2go_feedback.png';

    public const FEEDBACK_FORM_URL = 'https://docs.google.com/forms/d/e/1FAIpQLSeDX2c7-phFgH0TRPm4uSvGg40q6iMNKi-GkzkrD7CEFRWj7Q/viewform';

    public const PINGCON_FINANCE_CONTACT_EMAIL = 'finance@pingcon.com';

    public const PINGCON_FEEDBACK_IMAGE_URL = 'https://jelmir13.x02.me/i/PINGCON_feedback.png';

    public const PINGCON_FEEDBACK_FORM_URL = 'https://docs.google.com/forms/d/e/1FAIpQLSf4KIfvuDnewJUVk-YbA8N7oedxOxYzHEBJze8GMwvWfePz1g/viewform';

    public function __construct(
        public readonly ReceivingUpload $upload,
        public readonly string $transactionUrl,
        public readonly bool $attachFiles = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.from.address'), (string) config('mail.from.name')),
            subject: sprintf(
                'Receive %s sn=%d %s',
                $this->upload->uploadType->name,
                $this->upload->getKey(),
                $this->upload->created_at->format('Y-m-d H:i'),
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.receiving.upload-received',
            text: 'mail.receiving.upload-received-text',
            with: [
                'emailBranding' => $this->emailBranding(),
                'emailRows' => $this->emailRows(),
                'quality' => $this->calculateQualityPercentage(),
            ],
        );
    }

    /** @return array<int, array<string, string>> */
    public function emailRows(): array
    {
        $this->upload->loadMissing('extractions.activePurchaseOrderLink.poExtraction');
        $normalizer = app(PurchaseOrderDataNormalizer::class);
        $arrivalDate = $this->upload->upload_completed_at ?? $this->upload->created_at;

        return $this->upload->extractions
            ->map(function (AiExtraction $extraction) use ($normalizer, $arrivalDate): ?array {
                $data = $extraction->preferredData();
                if (! is_array($data)) {
                    return null;
                }

                $fields = collect($data['fields'] ?? [])
                    ->filter(fn (mixed $field): bool => is_array($field) && isset($field['label']))
                    ->mapWithKeys(function (array $field): array {
                        $value = $field['value'] ?? '';

                        return [
                            mb_strtolower(trim((string) $field['label'])) => is_scalar($value)
                                ? trim((string) $value)
                                : '',
                        ];
                    })
                    ->all();

                $linkedPo = $extraction->activePurchaseOrderLink?->poExtraction;
                $poDate = $linkedPo instanceof PoExtraction ? $linkedPo->po_date_value : null;
                $poDate ??= $normalizer->parseDate($extraction->po_date ?? ($fields['po date'] ?? null));
                $waitingDays = $normalizer->waitingDays($poDate, $arrivalDate);
                $fields['waiting time'] = match (true) {
                    $poDate === null => '',
                    $waitingDays === null => 'Date conflict',
                    default => $waitingDays.' '.($waitingDays === 1 ? 'day' : 'days'),
                };

                return $fields;
            })
            ->filter()
            ->values()
            ->all();
    }

    /** @return array{contact_name: string, contact_email: string, feedback_image_url: string, feedback_form_url: string} */
    public function emailBranding(): array
    {
        if (in_array($this->upload->uploadType->slug, ['pingcon', 'bonita'], true)) {
            return [
                'contact_name' => self::FINANCE_CONTACT_NAME,
                'contact_email' => self::PINGCON_FINANCE_CONTACT_EMAIL,
                'feedback_image_url' => self::PINGCON_FEEDBACK_IMAGE_URL,
                'feedback_form_url' => self::PINGCON_FEEDBACK_FORM_URL,
            ];
        }

        return [
            'contact_name' => self::FINANCE_CONTACT_NAME,
            'contact_email' => self::FINANCE_CONTACT_EMAIL,
            'feedback_image_url' => self::FEEDBACK_IMAGE_URL,
            'feedback_form_url' => self::FEEDBACK_FORM_URL,
        ];
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        if (! $this->attachFiles || $this->upload->files->sum('final_file_size') > 10 * 1024 * 1024) {
            return [];
        }

        $converter = app(ImageToPdfConverter::class);

        return $this->upload->files
            ->whereNotNull('r2_object_key')
            ->map(function ($file) use ($converter): Attachment {
                $extension = strtolower((string) $file->file_extension);
                $displayName = $file->sanitized_file_name;

                if ($extension === 'pdf') {
                    return Attachment::fromStorageDisk(
                        (string) config('receiving.disk'),
                        $file->r2_object_key,
                    )->as($displayName)->withMime('application/pdf');
                }

                if (! $converter->isSupported($extension)) {
                    return Attachment::fromStorageDisk(
                        (string) config('receiving.disk'),
                        $file->r2_object_key,
                    )->as($displayName)->withMime($file->content_type ?? 'application/octet-stream');
                }

                $pdfName = pathinfo($displayName, PATHINFO_FILENAME).'.pdf';

                return Attachment::fromData(
                    fn (): string => $converter->convertBytes($this->readAttachmentBytes((string) $file->r2_object_key)),
                    $pdfName,
                )->withMime('application/pdf');
            })
            ->values()
            ->all();
    }

    private function readAttachmentBytes(string $objectKey): string
    {
        $disk = (string) config('receiving.disk');

        try {
            return (string) Storage::disk($disk)->get($objectKey);
        } catch (Throwable $error) {
            throw new \RuntimeException(
                "Unable to read attachment {$objectKey} from disk {$disk}: ".$error->getMessage(),
                previous: $error,
            );
        }
    }

    /** @return array{percentage: int, deductions: array<string>} */
    public function calculateQualityPercentage(): array
    {
        $score = 100;
        $deductions = [];

        if ($this->upload->latitude === null) {
            $score -= 20;
            $deductions[] = 'Missing Location';
        }

        $extractions = $this->upload->extractions;
        if ($extractions->isNotEmpty()) {
            $perFileScore = 80 / $extractions->count();
            $perFieldScore = $perFileScore / 4;

            $rowIndex = 1;

            foreach ($extractions as $extraction) {
                $data = is_array($extraction->corrected_json) && $extraction->corrected_json !== []
                    ? $extraction->corrected_json
                    : $extraction->raw_extracted_json;

                if (! is_array($data)) {
                    $score -= $perFileScore;
                    $deductions[] = "Row {$rowIndex}: Missing AI data";

                    continue;
                }

                $fields = collect($data['fields'] ?? [])
                    ->filter(fn ($f) => is_array($f) && isset($f['label']))
                    ->mapWithKeys(fn ($f) => [mb_strtolower(trim((string) $f['label'])) => $f['value'] ?? '']);

                $requiredFields = ['po date', 'invoice date', 'po number'];
                foreach ($requiredFields as $req) {
                    $val = $fields->get($req);
                    if ($val === null || $val === '' || str_contains(strtolower($val), '[see image]')) {
                        $score -= $perFieldScore;
                        $deductions[] = "Row {$rowIndex}: Missing ".ucwords($req);
                    }
                }

                $items = $data['items'] ?? [];
                if (! is_array($items) || count($items) === 0) {
                    $score -= $perFieldScore;
                    $deductions[] = "Row {$rowIndex}: Missing Items";
                }

                $rowIndex++;
            }
        } else {
            $score -= 80;
            $deductions[] = 'No AI Extractions';
        }

        return [
            'percentage' => max(0, (int) round($score)),
            'deductions' => array_unique($deductions),
        ];
    }
}
