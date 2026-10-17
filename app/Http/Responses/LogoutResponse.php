<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;

/**
 * Signing out lands on the sign-in page, not the public front page
 * (walkthrough item 67).
 */
class LogoutResponse implements LogoutResponseContract
{
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        return $request->wantsJson()
            ? new JsonResponse('', 204)
            : redirect()->route('login');
    }
}
