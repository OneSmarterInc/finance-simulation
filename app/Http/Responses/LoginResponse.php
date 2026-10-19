<?php

namespace App\Http\Responses;

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

        if ($user?->admin !== null) {
            return redirect()->route('admin.dashboard');
        }

        if ($user?->faculty !== null) {
            return redirect()->route('dashboard');
        }

        if ($user?->student !== null) {
            return redirect()->route('student.courses');
        }

        return redirect()->route('dashboard');
    }
}
