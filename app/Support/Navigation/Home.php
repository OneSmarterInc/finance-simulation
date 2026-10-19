<?php

namespace App\Support\Navigation;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Where each signed-in person's own pages start: admins on Admin › Dashboard,
 * faculty on their dashboard, students on My courses. An account with none of
 * these roles has no home yet.
 */
final class Home
{
    public const NO_ACCESS = 'This account isn\'t set up for any course yet. Students join from the course link their instructor sends; faculty and admins are added by One Smarter.';

    public static function routeFor(?User $user): ?string
    {
        return match (true) {
            $user === null => null,
            $user->admin !== null => 'admin.dashboard',
            $user->faculty !== null => 'dashboard',
            $user->student !== null => 'student.courses',
            default => null,
        };
    }

    /** An account with no role is signed out again and told why, rather than left on a bare 403. */
    public static function signOut(): RedirectResponse
    {
        Auth::guard('web')->logout();
        session()->invalidate();
        session()->regenerateToken();

        return redirect()->route('login')->with('status', self::NO_ACCESS);
    }
}
