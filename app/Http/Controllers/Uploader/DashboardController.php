<?php

namespace App\Http\Controllers\Uploader;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): RedirectResponse|Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $uploadTypes = $user->uploadTypes()
            ->wherePivot('is_active', true)
            ->where('upload_types.is_active', true)
            ->orderBy('name')
            ->get(['upload_types.id', 'name', 'slug']);

        abort_if($uploadTypes->isEmpty(), 403, 'No active receiving page is assigned to this account.');

        if ($uploadTypes->count() === 1) {
            return redirect()->route('receiving.upload.show', $uploadTypes->first());
        }

        return Inertia::render('uploader/dashboard', [
            'uploadTypes' => $uploadTypes,
        ]);
    }
}
