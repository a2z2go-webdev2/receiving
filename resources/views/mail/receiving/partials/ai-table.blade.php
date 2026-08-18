@php
    /** @var array<int, array<string, string>> $emailRows */
    $emailColumns = ['Company Name', 'Address', 'TIN', 'Invoice Number', 'PO Number', 'Invoice Date', 'PO Date', 'Waiting Time'];
@endphp
@if ($emailRows !== [])
    <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;max-width:100%;">
        <table style="border-collapse:collapse;border:1px solid #d9e2ec;width:100%;min-width:480px;font-size:13px;margin:0 0 18px;font-family:Arial,Helvetica,sans-serif;table-layout:auto;">
            <thead>
                <tr>
                    <th style="text-align:center;padding:8px 12px;background:#eef2f7;color:#1f4e78;font-size:12px;border:1px solid #d9e2ec;white-space:nowrap;">#</th>
                    @foreach ($emailColumns as $column)
                        <th style="text-align:left;padding:8px 12px;background:#eef2f7;color:#1f4e78;font-size:12px;border:1px solid #d9e2ec;white-space:nowrap;">{{ $column }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($emailRows as $row)
                    <tr>
                        <td style="text-align:center;padding:8px 12px;color:#1f2937;border:1px solid #d9e2ec;vertical-align:top;font-weight:bold;background:#f8fafc;">
                            {{ $loop->iteration }}
                        </td>
                        @foreach ($emailColumns as $column)
                            @php $value = $row[mb_strtolower($column)] ?? ''; @endphp
                            <td style="padding:8px 12px;color:#1f2937;border:1px solid #d9e2ec;vertical-align:top;">
                                {{ $value === '' ? '—' : $value }}
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
