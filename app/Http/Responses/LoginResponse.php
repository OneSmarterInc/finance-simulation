<?php

namespace App\Http\Responses;

use App\Support\Navigation\Home;
use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): RedirectResponse
    {
        $user = $request->user();

        // U3: an admin signing in to connect the reporting connector goes back to its consent page.
        $intended = $request->session()->get('url.intended');
        if ($user?->admin !== null && is_string($intended) && str_starts_with($intended, route('reporting.oauth.authorize'))) {
            return redirect()->intended();
        }

        $home = Home::routeFor($user);

        return $home !== null ? redirect()->route($home) : Home::signOut();
    }
}
