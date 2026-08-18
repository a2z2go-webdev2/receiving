<?php

namespace App\Http\Controllers\Admin;

use App\Features\Receiving\Services\ActivityLogger;
use App\Http\Controllers\Controller;
use App\Models\UploadType;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ReceivingSettingsController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('admin/settings/index', [
            'uploadTypes' => UploadType::query()->orderBy('name')->get(['id', 'name', 'slug', 'is_active']),
        ]);
    }

    public function toggleUploadType(UploadType $uploadType, ActivityLogger $activity): RedirectResponse
    {
        $uploadType->forceFill(['is_active' => ! $uploadType->is_active])->save();
        $activity->record('admin', 'upload_type_status_changed', 'success', "Upload type {$uploadType->name} status changed.", request()->user(), null, request());

        return back()->with('status', 'Upload type status updated.');
    }
}
