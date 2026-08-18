@php
    /** @var \App\Models\ReceivingUpload $upload */
    /** @var array{contact_name: string, contact_email: string, feedback_image_url: string, feedback_form_url: string} $emailBranding */
    /** @var array<int, array<string, string>> $emailRows */
    $serialNumber = $upload->getKey();
    $emailColumns = ['Company Name', 'Address', 'TIN', 'Invoice Number', 'PO Number', 'Invoice Date', 'PO Date', 'Waiting Time'];
@endphp
Receiving upload #{{ $serialNumber }} ({{ $upload->uploadType->name }}) submitted successfully.

@if ($emailRows !== [])
@foreach ($emailRows as $row)
Row {{ $loop->iteration }}:
@foreach ($emailColumns as $column)
- {{ $column }}: {{ ($row[mb_strtolower($column)] ?? '') === '' ? '—' : $row[mb_strtolower($column)] }}
@endforeach

@endforeach
@else
No AI extraction data is available yet for this upload.
@endif

Notification
Upload Quality: {{ $quality['percentage'] }}%
@if (!empty($quality['deductions']))
Notes:
@foreach ($quality['deductions'] as $note)
- {{ $note }}
@endforeach
@endif

Please do not hesitate to contact:
Finance: {{ $emailBranding['contact_name'] }} ({{ $emailBranding['contact_email'] }})

Feedback: {{ $emailBranding['feedback_form_url'] }}
