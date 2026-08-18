<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UploadWorkflow;
use App\Features\Receiving\Services\ActivityLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EmailRecipientRequest;
use App\Models\EmailRecipient;
use App\Models\UploadType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmailRecipientController extends Controller
{
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'upload_type_id' => ['nullable', 'integer', 'exists:upload_types,id'],
        ]);
        $uploadTypes = UploadType::query()
            ->where('workflow', UploadWorkflow::Standard)
            ->orderBy('name')
            ->get(['id', 'name']);
        $recipientCounts = EmailRecipient::query()
            ->selectRaw('upload_type_id, COUNT(*) AS recipient_count')
            ->groupBy('upload_type_id')
            ->pluck('recipient_count', 'upload_type_id');
        $activeUploadTypeId = isset($validated['upload_type_id'])
            ? (int) $validated['upload_type_id']
            : $uploadTypes->first()?->getKey();

        return Inertia::render('admin/recipients/index', [
            'uploadTypes' => $uploadTypes->map(fn (UploadType $uploadType): array => [
                'id' => $uploadType->getKey(),
                'name' => $uploadType->name,
                'recipient_count' => (int) $recipientCounts->get($uploadType->getKey(), 0),
            ]),
            'activeUploadTypeId' => $activeUploadTypeId,
            'recipients' => EmailRecipient::query()
                ->with('uploadType:id,name')
                ->when($activeUploadTypeId !== null, fn ($query) => $query->where('upload_type_id', $activeUploadTypeId))
                ->orderBy('email')
                ->paginate(20)
                ->withQueryString()
                ->through(fn (EmailRecipient $recipient): array => [
                    'id' => $recipient->getKey(),
                    'upload_type_id' => $recipient->upload_type_id,
                    'upload_type' => $recipient->uploadType->name,
                    'email' => $recipient->email,
                    'type' => $recipient->type,
                    'is_active' => $recipient->is_active,
                ]),
        ]);
    }

    public function store(EmailRecipientRequest $request, ActivityLogger $activity): RedirectResponse
    {
        abort_if(
            EmailRecipient::query()->where('upload_type_id', $request->integer('upload_type_id'))->count() >= 50,
            422,
            'Each upload type is limited to 50 configured recipients.',
        );
        $recipient = EmailRecipient::query()->create($request->validated());
        $activity->record('admin', 'email_recipient_created', 'success', "Recipient {$recipient->email} created.", $request->user(), null, $request);

        return back()->with('status', 'Email recipient added.');
    }

    public function update(EmailRecipientRequest $request, EmailRecipient $recipient, ActivityLogger $activity): RedirectResponse
    {
        $recipient->update($request->validated());
        $activity->record('admin', 'email_recipient_updated', 'success', "Recipient {$recipient->email} updated.", $request->user(), null, $request);

        return back()->with('status', 'Email recipient updated.');
    }

    public function destroy(EmailRecipient $recipient, ActivityLogger $activity): RedirectResponse
    {
        $email = $recipient->email;
        $recipient->delete();
        $activity->record('admin', 'email_recipient_removed', 'success', "Recipient {$email} removed.", request()->user(), null, request());

        return back()->with('status', 'Email recipient removed.');
    }
}
