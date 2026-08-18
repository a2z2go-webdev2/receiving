<?php

namespace App\Http\Controllers;

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

        if ($user instanceof User) {
            $routeName = $user->dashboardRouteName();

            if ($routeName !== 'dashboard') {
                return new RedirectResponse(route($routeName, absolute: false));
            }
        }

        return Inertia::render('dashboard');
    }
}
