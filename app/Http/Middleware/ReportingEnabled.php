<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** U3: the reporting connector answers only while FLEXEE_REPORTING_ENABLED is on (it is by default). */
class ReportingEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('flexee.reporting.enabled'), 404);

        return $next($request);
    }
}
