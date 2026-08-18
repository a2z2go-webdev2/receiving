<x-mail::message>
# Documents Ready for Review

AI extraction for **{{ $upload->uploadType->name }} SN-{{ $upload->getKey() }}** is ready for human review. Compare every field with the scanned document before verification.

<x-mail::button :url="$reviewUrl">
Review extracted data
</x-mail::button>

This secure link expires automatically. Do not forward it.

Thanks,  
{{ config('app.name') }}
</x-mail::message>
