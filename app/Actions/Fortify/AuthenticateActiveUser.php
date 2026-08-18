<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Fortify;

class AuthenticateActiveUser
{
    public function __invoke(Request $request): ?User
    {
        $guard = Auth::guard(config('fortify.guard', config('auth.defaults.guard')));
        $provider = $guard->getProvider();
        $credentials = $request->only(Fortify::username(), 'password');
        $user = $provider->retrieveByCredentials($credentials);

        if (! $user || ! $provider->validateCredentials($user, ['password' => $request->password])) {
            return null;
        }

        if (config('hashing.rehash_on_login', true) && method_exists($provider, 'rehashPasswordIfRequired')) {
            $provider->rehashPasswordIfRequired($user, ['password' => $request->password]);
        }

        if (! $user instanceof User || ! $user->isActive()) {
            $request->attributes->set('auth.failure_reason_code', 'account_not_active');
            $request->attributes->set('auth.failure_target_user_id', $user instanceof User ? $user->getKey() : null);

            return null;
        }

        return $user;
    }
}
