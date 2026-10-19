<?php

namespace App\Http\Controllers\Reporting;

use App\Http\Controllers\Controller;
use App\Services\Reporting\ReportingOAuth;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * U3 (RP3): the sign-in a person sees when they connect the reporting
 * connector in Claude. Only One Smarter admins can approve; everyone else is
 * told so and nothing is issued.
 */
class ReportingConsentController extends Controller
{
    private const FIELDS = ['client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource'];

    public function __construct(private ReportingOAuth $oauth) {}

    public function show(Request $request): View|Response|RedirectResponse
    {
        $params = $request->only(self::FIELDS);
        $check = $this->oauth->check($params);
        if (isset($check['error']) && ! $check['redirectable']) {
            return response()->view('reporting.consent-error', ['message' => $check['description']], 400);
        }
        if (isset($check['error'])) {
            return $this->back($check['redirect'], ['error' => $check['error'], 'error_description' => $check['description']], $params);
        }
        if (! $request->user()->isAdmin()) {
            return response()->view('reporting.consent-error', ['message' => 'Only One Smarter admins can connect the Flexee reporting connector. You\'re signed in as '.$request->user()->email.'.'], 403);
        }

        return view('reporting.consent', [
            'params' => $params,
            'client' => $check['client'],
            'returnHost' => (string) parse_url($check['redirect'], PHP_URL_HOST),
            'resource' => ReportingOAuth::resource(),
        ]);
    }

    public function decide(Request $request): Response|RedirectResponse
    {
        $params = $request->only(self::FIELDS);
        $check = $this->oauth->check($params);
        if (isset($check['error']) && ! $check['redirectable']) {
            return response()->view('reporting.consent-error', ['message' => $check['description']], 400);
        }
        if (isset($check['error'])) {
            return $this->back($check['redirect'], ['error' => $check['error'], 'error_description' => $check['description']], $params);
        }
        if ($request->input('decision') !== 'approve' || ! $request->user()->isAdmin()) {
            return $this->back($check['redirect'], ['error' => 'access_denied', 'error_description' => 'The connection was not approved.'], $params);
        }
        $code = $this->oauth->issueCode($check['client'], $request->user(), $check['redirect'], (string) $params['code_challenge']);

        return $this->back($check['redirect'], ['code' => $code], $params);
    }

    /** Back to the client, with state and the issuer (RFC 9207). */
    private function back(string $redirect, array $query, array $params): RedirectResponse
    {
        if (filled($params['state'] ?? null)) {
            $query['state'] = $params['state'];
        }
        $query['iss'] = ReportingOAuth::issuer();

        return redirect()->away($redirect.(str_contains($redirect, '?') ? '&' : '?').http_build_query($query));
    }
}
