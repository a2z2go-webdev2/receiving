@php
    /** @var \App\Models\ReceivingUpload $upload */
    /** @var array{contact_name: string, contact_email: string, feedback_image_url: string, feedback_form_url: string} $emailBranding */
    $serialNumber = $upload->getKey();
    $uploadTypeName = $upload->uploadType->name;
    $hasExtractions = $upload->extractions->isNotEmpty();
@endphp
<div style="font-family:Arial,Helvetica,sans-serif;max-width:760px;width:100%;color:#1f2937;background:#ffffff;-webkit-text-size-adjust:100%;">
    <div style="background:#1f4e78;color:#ffffff;padding:16px 20px;border-radius:14px 14px 0 0;">
        <h2 style="margin:0;font-size:18px;">Receiving Upload Notification</h2>
        <p style="margin:6px 0 0;font-size:12px;opacity:0.92;">Serial Number: {{ $serialNumber }}</p>
    </div>
    <div style="border:1px solid #d9e2ec;border-top:none;padding:16px;border-radius:0 0 14px 14px;">
        @if ($hasExtractions)
            @include('mail.receiving.partials.ai-table', ['emailRows' => $emailRows])
        @else
            <p style="margin:0 0 16px;font-size:13px;color:#6b7280;">No AI extraction data is available yet for this upload.</p>
        @endif

        <div style="margin-top:24px;border-top:1px solid #e5e7eb;padding-top:16px;">
            <h3 style="margin:0 0 12px;font-size:15px;color:#1f4e78;">Notification</h3>
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">
                <span style="font-size:13px;color:#374151;font-weight:bold;">Upload Quality:</span>
                @php
                    $badgeColor = $quality['percentage'] >= 80 ? '#10b981' : ($quality['percentage'] >= 50 ? '#f59e0b' : '#ef4444');
                @endphp
                <span style="display:inline-block;padding:4px 8px;border-radius:9999px;font-size:12px;font-weight:bold;color:#fff;background-color:{{ $badgeColor }};">
                    {{ $quality['percentage'] }}%
                </span>
            </div>
            @if (!empty($quality['deductions']))
                <div style="font-size:12px;color:#6b7280;margin-top:4px;">
                    <strong>Notes:</strong>
                    <ul style="margin:4px 0 0;padding-left:20px;">
                        @foreach ($quality['deductions'] as $note)
                            <li>{{ $note }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        <div style="margin-top:24px;font-size:13px;line-height:1.6;color:#374151;">
            <p style="margin:0;">Please do not hesitate to contact:</p>
            <p style="margin:4px 0 0 0;">
                <strong>Finance:</strong>
                {{ $emailBranding['contact_name'] }} &mdash;
                <a href="mailto:{{ $emailBranding['contact_email'] }}" style="color:#1f4e78;text-decoration:none;">{{ $emailBranding['contact_email'] }}</a>
            </p>
        </div>

        @if ($emailBranding['feedback_image_url'])
            <div style="margin:25px 0;text-align:left;">
                <a href="{{ $emailBranding['feedback_form_url'] }}" target="_blank" rel="noopener">
                    <img src="{{ $emailBranding['feedback_image_url'] }}" alt="Feedback" style="max-width:280px;width:100%;border-radius:5px;display:block;">
                </a>
            </div>
        @endif
    </div>
</div>
