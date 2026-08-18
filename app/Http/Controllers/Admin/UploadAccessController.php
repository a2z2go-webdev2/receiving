<?php

namespace App\Http\Controllers\Admin;

use App\Features\Receiving\Services\ActivityLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUploadAccessRequest;
use App\Models\UploadType;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class UploadAccessController extends Controller
{
    public function index(): Response
    {
        $users = User::query()
            ->with(['uploadAccesses', 'roles'])
            ->orderBy('email')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (User $user): array => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status->value,
                'role' => $user->getRoleNames()->first(),
                'upload_type_ids' => $user->uploadAccesses->where('is_active', true)->pluck('upload_type_id')->values(),
            ]);

        return Inertia::render('admin/access/index', [
            'uploadTypes' => UploadType::query()->orderBy('name')->get(['id', 'name', 'slug', 'is_active']),
            'users' => $users,
        ]);
    }

    public function update(UpdateUploadAccessRequest $request, User $user, ActivityLogger $activity): RedirectResponse
    {
        $selected = collect($request->validated('upload_type_ids'))->map(fn ($id): int => (int) $id);
        $actor = $request->user();

        DB::transaction(function () use ($user, $selected, $actor): void {
            foreach (UploadType::query()->pluck('id') as $typeId) {
                $user->uploadAccesses()->updateOrCreate(
                    ['upload_type_id' => $typeId],
                    ['is_active' => $selected->contains($typeId), 'created_by' => $actor?->getKey()],
                );
            }
        });
        $activity->record('admin', 'upload_access_changed', 'success', "Upload access changed for {$user->email}.", $actor, null, $request);

        return back()->with('status', 'Upload access updated immediately.');
    }
}
