<?php

namespace App\Http\Controllers\Driver;

use App\Features\Receiving\Services\ActivityLogger;
use App\Features\Warehouse\Services\WarehouseOperations;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WarehouseDelivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DriverOperationsController extends Controller
{
    public function deliver(
        Request $request,
        WarehouseDelivery $delivery,
        WarehouseOperations $operations,
        ActivityLogger $activity,
    ): RedirectResponse {
        $validated = $request->validate([
            'delivery_location' => ['nullable', 'string', 'max:255'],
        ]);

        $actor = $this->actor($request->user());

        $operations->deliver(
            $delivery,
            now()->toDateTimeString(),
            $validated['delivery_location'] ?? 'Unknown location',
            $actor
        );

        $activity->record('driver', 'delivery_completed', 'success', "Delivery {$delivery->getKey()} was marked delivered by driver {$actor->email} at location: ".($validated['delivery_location'] ?? 'Unknown location').'.', $actor, null, $request);

        return back()->with('status', 'Customer delivery marked delivered.');
    }

    private function actor(mixed $user): User
    {
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
