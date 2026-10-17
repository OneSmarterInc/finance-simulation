<?php

namespace App\Http\Middleware;

use App\Models\Firm;
use App\Services\Students\StudentAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** N3: a student not yet marked paid sees a plain page, not the firm's pages (no 403). */
class EnsureStudentAccessConfirmed
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $firm = $request->route('firm');

        if ($user?->student && ! $user->faculty && $firm instanceof Firm
            && ! StudentAccess::isPaid($user->student, $firm->course)) {
            return response()->view('access-pending', ['firm' => $firm], 200);
        }

        return $next($request);
    }
}
