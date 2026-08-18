<?php

namespace App\Http\Controllers\Admin;

use App\Features\Receiving\Services\ActivityLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\UploadType;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        $users = User::query()->with(['roles:id,name', 'uploadTypes:id,name'])->latest()->paginate(20)->withQueryString()->through(fn (User $user): array => [
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status->value,
            'role' => $user->getRoleNames()->first(),
            'upload_types' => $user->uploadTypes->filter(function ($type) {
                /** @var UploadType $type */
                return $type->pivot->is_active;
            })->pluck('name')->values(),
            'created_at' => $user->created_at->toISOString(),
        ]);

        return Inertia::render('admin/users/index', ['users' => $users]);
    }

    public function store(StoreUserRequest $request, ActivityLogger $activity): RedirectResponse
    {
        $data = $request->validated();
        $user = DB::transaction(function () use ($data): User {
            $user = User::query()->make([
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                'password' => $data['password'],
                'status' => $data['status'],
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->syncRoles([$data['role']]);

            return $user;
        });
        $activity->record('admin', 'user_created', 'success', "User {$user->email} created.", $request->user(), null, $request);

        return back()->with('status', 'User account created.');
    }

    public function update(UpdateUserRequest $request, User $user, ActivityLogger $activity): RedirectResponse
    {
        $data = $request->validated();
        DB::transaction(function () use ($data, $user): void {
            $user->update(['name' => $data['name'], 'email' => strtolower($data['email']), 'status' => $data['status']]);
            $user->syncRoles([$data['role']]);
        });
        $activity->record('admin', 'user_updated', 'success', "User {$user->email} updated.", $request->user(), null, $request);

        return back()->with('status', 'User account updated.');
    }

    public function resetPassword(Request $request, User $user, ActivityLogger $activity): RedirectResponse
    {
        $validated = $request->validate(['password' => ['required', 'confirmed', Password::min(6)]]);
        $user->forceFill(['password' => Hash::make($validated['password']), 'remember_token' => null])->save();
        $activity->record('admin', 'user_password_reset', 'success', "Temporary password set for {$user->email}.", $request->user(), null, $request);

        return back()->with('status', 'Temporary password set.');
    }
}
