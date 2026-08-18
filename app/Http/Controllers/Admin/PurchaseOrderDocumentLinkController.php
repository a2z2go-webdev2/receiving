<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PurchaseOrderLinkSource;
use App\Features\Receiving\Services\ActivityLogger;
use App\Features\Receiving\Services\PurchaseOrderLinker;
use App\Http\Controllers\Controller;
use App\Models\AiExtraction;
use App\Models\PoExtraction;
use App\Models\PurchaseOrderDocumentLink;
use App\Models\ReceivingUpload;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PurchaseOrderDocumentLinkController extends Controller
{
    public function store(
        Request $request,
        ReceivingUpload $upload,
        AiExtraction $extraction,
        PurchaseOrderLinker $linker,
        ActivityLogger $activity,
    ): RedirectResponse {
        $this->authorize('view', $upload);
        abort_unless($extraction->receiving_upload_id === $upload->getKey(), 404);

        $validated = $request->validate([
            'po_extraction_id' => ['required', 'integer', 'exists:po_extractions,id'],
        ]);

        /** @var PoExtraction $poExtraction */
        $poExtraction = PoExtraction::query()->findOrFail($validated['po_extraction_id']);
        /** @var User|null $actor */
        $actor = $request->user();

        $link = $linker->link($extraction, $poExtraction, $actor, PurchaseOrderLinkSource::Manual);
        $activity->record(
            'purchase_order',
            'purchase_order_manually_linked',
            'success',
            "Purchase order {$poExtraction->po_number} was linked to SN-{$upload->getKey()}.",
            $actor,
            $upload,
            $request,
        );

        return back()->with('status', "Linked to purchase order {$link->poExtraction->po_number}.");
    }

    public function destroy(
        Request $request,
        ReceivingUpload $upload,
        AiExtraction $extraction,
        PurchaseOrderLinker $linker,
        ActivityLogger $activity,
    ): RedirectResponse {
        $this->authorize('view', $upload);
        abort_unless($extraction->receiving_upload_id === $upload->getKey(), 404);

        $link = PurchaseOrderDocumentLink::query()
            ->active()
            ->where('ai_extraction_id', $extraction->getKey())
            ->with('poExtraction')
            ->firstOrFail();
        /** @var User|null $actor */
        $actor = $request->user();

        $poNumber = $link->poExtraction->po_number;
        $linker->unlink($link, $actor);
        $activity->record(
            'purchase_order',
            'purchase_order_unlinked',
            'success',
            "Purchase order {$poNumber} was unlinked from SN-{$upload->getKey()}.",
            $actor,
            $upload,
            $request,
        );

        return back()->with('status', 'Purchase order link removed.');
    }
}
