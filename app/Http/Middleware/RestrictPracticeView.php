<?php

namespace App\Http\Middleware;

use App\Services\Practice\PracticeViewAs;
use Closure;
use Illuminate\Http\Request;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * U9 view-as: while a faculty member views a practice run as a reference
 * team, the session stays on that team's student pages. Anything else
 * (settings, signing out, other pages) leads back to the team's dashboard,
 * where the banner offers the way back to the faculty view.
 */
class RestrictPracticeView
{
    public function __construct(private readonly PracticeViewAs $views) {}

    public function handle(Request $request, Closure $next): Response
    {
        $view = $this->views->current($request);
        if ($view === null) {
            return $next($request);
        }

        $name = (string) $request->route()?->getName();
        if (in_array($name, PracticeViewAs::ALLOWED_ROUTES, true)) {
            return $next($request);
        }
        // Livewire's update endpoint itself passes; it replays this middleware
        // (registered as persistent) against the page each component came from.
        if (str_ends_with($name, 'livewire.update')) {
            return $next($request);
        }
        // That replay, for a page off limits (a component loaded before the switch).
        abort_if(app(HandleRequests::class)->isLivewireRoute(), 403);
        if ($name === 'logout') {
            return redirect()->route('practice.back')->setStatusCode(307);
        }

        return redirect()->route('firms.dashboard', $view['firm_id'])
            ->with('status', 'While viewing a practice run as a team, only that team\'s pages are open. Use "Back to faculty view" to leave.');
    }
}
