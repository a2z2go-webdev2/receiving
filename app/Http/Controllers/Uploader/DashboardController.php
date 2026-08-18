<?php

namespace App\Http\Controllers\Uploader;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $uploadType = $user->uploadTypes()
            ->wherePivot('is_active', true)
            ->where('upload_types.is_active', true)
            ->orderBy('name')
            ->first(['upload_types.id', 'name', 'slug']);

        abort_if($uploadType === null, 403, 'No active receiving page is assigned to this account.');

        return redirect()->route('receiving.upload.show', $uploadType);
    }
}
