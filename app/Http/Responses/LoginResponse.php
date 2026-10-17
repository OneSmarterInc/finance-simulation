<?php

namespace App\Http\Responses;

use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): RedirectResponse
    {
        $user = $request->user();

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