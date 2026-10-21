<?php

namespace App\Http\Middleware;

use App\Models\Firm;
use App\Services\Students\StudentAccess;
use Closure;
use Illuminate\Http\Request;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Symfony\Component\HttpFoundation\Response;

/** N3: a student not yet marked paid sees a plain page, not the firm's pages (no 403). */
class EnsureStudentAccessConfirmed
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $firm = $request->route('firm');

        // R12: a removed course takes its firms' pages with it.
        abort_if($firm instanceof Firm && $firm->course === null, 404);

        if ($user?->student && ! $user->faculty && $firm instanceof Firm
            && ! StudentAccess::isPaid($user->student, $firm->course)) {
            // Livewire re-runs this middleware for actions with a faked GET request,
            // and ignores a non-redirect response, so refuse outright there.
            abort_if(app(HandleRequests::class)->isLivewireRoute(), 403);

            return response()->view('access-pending', ['firm' => $firm], 200);
        }

        return $next($request);
    }
}
