<?php

namespace App\Http\Controllers\Warehouse;

use App\Features\Receiving\Services\ActivityLogger;
use App\Features\Warehouse\Services\WarehouseOperations;
use App\Http\Controllers\Controller;
use App\Http\Requests\Warehouse\ConfirmWarehouseArrivalRequest;
use App\Http\Requests\Warehouse\ConfirmWarehouseArrivalsByPoRequest;
use App\Http\Requests\Warehouse\DeliverWarehouseDeliveryRequest;
use App\Http\Requests\Warehouse\DispatchBulkWarehouseDeliveriesRequest;
use App\Http\Requests\Warehouse\DispatchWarehouseDeliveryRequest;
use App\Http\Requests\Warehouse\StoreBulkWarehouseDeliveryRequest;
use App\Http\Requests\Warehouse\StoreOpeningStockRequest;
use App\Http\Requests\Warehouse\StoreWarehouseDeliveryRequest;
use App\Models\PurchaseOrderItemArrival;
use App\Models\User;
use App\Models\WarehouseDelivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WarehouseOperationsController extends Controller
{
    public function confirmArrival(
        ConfirmWarehouseArrivalRequest $request,
        PurchaseOrderItemArrival $arrival,
        WarehouseOperations $operations,
        ActivityLogger $activity,
    ): RedirectResponse {
        $actor = $this->actor($request->user());
        $lot = $operations->confirmArrival($arrival, $request->validated(), $actor);
        $activity->record('warehouse', 'arrival_confirmed', 'success', "Stock lot {$lot->getKey()} was confirmed in the warehouse.", $actor, $arrival->upload, $request);

        return back()->with('status', 'Items confirmed as placed in warehouse stock. Dwell tracking has started.');
    }

    public function confirmArrivalsByPo(
        ConfirmWarehouseArrivalsByPoRequest $request,
        WarehouseOperations $operations,
        ActivityLogger $activity,
    ): RedirectResponse {
        $actor = $this->actor($request->user());
        $validated = $request->validated();
        $lots = $operations->confirmArrivalsByPo($validated, $actor);
        $count = $lots->count();
        $poNumber = $validated['po_number'];
        $activity->record('warehouse', 'arrivals_confirmed_by_po', 'success', "{$count} item(s) from PO {$poNumber} confirmed in the warehouse.", $actor, null, $request);

        return back()->with('status', "{$count} item(s) from PO {$poNumber} confirmed as placed in warehouse stock.");
    }

    public function storeOpeningStock(
        StoreOpeningStockRequest $request,
        WarehouseOperations $operations,
        ActivityLogger $activity,
    ): RedirectResponse {
        $actor = $this->actor($request->user());
        $lot = $operations->addOpeningStock($request->validated(), $actor);
        $activity->record('warehouse', 'opening_stock_added', 'success', "Opening stock lot {$lot->getKey()} was added.", $actor, null, $request);

        return back()->with('status', 'Opening stock added.');
    }

    public function storeDelivery(
        StoreWarehouseDeliveryRequest $request,
        WarehouseOperations $operations,
        ActivityLogger $activity,
    ): RedirectResponse {
        $actor = $this->actor($request->user());
        $delivery = $operations->createDelivery($request->validated(), $actor);
        $activity->record('warehouse', 'delivery_created', 'success', "Delivery {$delivery->getKey()} was created as a draft.", $actor, null, $request);

        return back()->with('status', 'Customer delivery created as a draft.');
    }

    public function storeBulkDeliveries(
        StoreBulkWarehouseDeliveryRequest $request,
        WarehouseOperations $operations,
        ActivityLogger $activity,
    ): RedirectResponse {
        $actor = $this->actor($request->user());
        $validated = $request->validated();
        $dispatchImmediately = (bool) ($validated['dispatch_immediately'] ?? false);
        $deliveries = $operations->createBulkDeliveries($validated['deliveries'], $actor, $dispatchImmediately);
        $count = $deliveries->count();

        $message = $dispatchImmediately
            ? "{$count} customer delivery(ies) created and dispatched for truck shipment."
            : "{$count} customer delivery(ies) created as drafts.";

        $activity->record('warehouse', 'bulk_deliveries_created', 'success', "{$count} customer delivery(ies) were created.", $actor, null, $request);

        return back()->with('status', $message);
    }

    public function dispatch(
        DispatchWarehouseDeliveryRequest $request,
        WarehouseDelivery $delivery,
        WarehouseOperations $operations,
        ActivityLogger $activity,
    ): RedirectResponse {
        $actor = $this->actor($request->user());
        $operations->dispatch($delivery, now()->toDateTimeString(), $actor);
        $activity->record('warehouse', 'delivery_dispatched', 'success', "Delivery {$delivery->getKey()} was dispatched with FIFO stock allocation.", $actor, null, $request);

        return back()->with('status', 'Delivery dispatched and stock allocated by FIFO.');
    }

    public function dispatchBulk(
        DispatchBulkWarehouseDeliveriesRequest $request,
        WarehouseOperations $operations,
        ActivityLogger $activity,
    ): RedirectResponse {
        $actor = $this->actor($request->user());
        $validated = $request->validated();
        $dispatched = $operations->dispatchBulk($validated['delivery_ids'], now()->toDateTimeString(), $actor);
        $count = $dispatched->count();
        $activity->record('warehouse', 'bulk_deliveries_dispatched', 'success', "{$count} delivery(ies) were dispatched for truck shipment.", $actor, null, $request);

        return back()->with('status', "{$count} customer delivery(ies) dispatched and allocated by FIFO.");
    }

    public function deliver(
        DeliverWarehouseDeliveryRequest $request,
        WarehouseDelivery $delivery,
        WarehouseOperations $operations,
        ActivityLogger $activity,
    ): RedirectResponse {
        $actor = $this->actor($request->user());
        $operations->deliver($delivery, now()->toDateTimeString(), null, $actor);
        $activity->record('warehouse', 'delivery_completed', 'success', "Delivery {$delivery->getKey()} was marked delivered.", $actor, null, $request);

        return back()->with('status', 'Customer delivery marked delivered.');
    }

    public function destroyDelivery(
        Request $request,
        WarehouseDelivery $delivery,
        WarehouseOperations $operations,
        ActivityLogger $activity,
    ): RedirectResponse {
        $actor = $this->actor($request->user());
        $operations->deleteDraftDelivery($delivery, $actor);
        $activity->record('warehouse', 'delivery_deleted', 'success', "Draft delivery {$delivery->getKey()} was deleted.", $actor, null, $request);

        return back()->with('status', 'Draft delivery deleted.');
    }

    public function updateDelivery(
        StoreWarehouseDeliveryRequest $request,
        WarehouseDelivery $delivery,
        WarehouseOperations $operations,
        ActivityLogger $activity,
    ): RedirectResponse {
        $actor = $this->actor($request->user());
        $updated = $operations->updateDraftDelivery($delivery, $request->validated(), $actor);
        $activity->record('warehouse', 'delivery_updated', 'success', "Draft delivery {$updated->getKey()} was updated.", $actor, null, $request);

        return back()->with('status', 'Draft delivery updated.');
    }

    public function updateShipment(
        StoreBulkWarehouseDeliveryRequest $request,
        string $shipmentReference,
        WarehouseOperations $operations,
        ActivityLogger $activity,
    ): RedirectResponse {
        $actor = $this->actor($request->user());
        $validated = $request->validated();
        $updated = $operations->updateShipmentDeliveries($shipmentReference, $validated['deliveries'], $actor);
        $count = $updated->count();
        $activity->record('warehouse', 'shipment_updated', 'success', "Shipment {$shipmentReference} with {$count} delivery(ies) was updated.", $actor, null, $request);

        return back()->with('status', 'Truck shipment updated.');
    }

    public function destroyShipment(
        Request $request,
        string $shipmentReference,
        WarehouseOperations $operations,
        ActivityLogger $activity,
    ): RedirectResponse {
        $actor = $this->actor($request->user());
        $operations->deleteDraftShipment($shipmentReference, $actor);
        $activity->record('warehouse', 'shipment_deleted', 'success', "Draft shipment {$shipmentReference} was deleted.", $actor, null, $request);

        return back()->with('status', 'Draft truck shipment deleted.');
    }

    public function dispatchShipment(
        Request $request,
        string $shipmentReference,
        WarehouseOperations $operations,
        ActivityLogger $activity,
    ): RedirectResponse {
        $actor = $this->actor($request->user());
        $dispatched = $operations->dispatchShipment($shipmentReference, $actor);
        $count = $dispatched->count();
        $activity->record('warehouse', 'shipment_dispatched', 'success', "Shipment {$shipmentReference} with {$count} delivery(ies) was dispatched.", $actor, null, $request);

        return back()->with('status', "Truck shipment ({$count} customer deliveries) dispatched and allocated by FIFO.");
    }

    private function actor(mixed $user): User
    {
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
