<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DenyUploaderOnlyAccountSettings
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_if(
            $user instanceof User && ! $user->canManageAccountSettings(),
            403,
            'Account settings are not available for uploader accounts.',
        );

        return $next($request);
    }
}
