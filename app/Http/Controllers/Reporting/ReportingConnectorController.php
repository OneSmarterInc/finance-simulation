<?php

namespace App\Http\Controllers\Reporting;

use App\Http\Controllers\Controller;
use App\Services\Reporting\McpServer;
use App\Services\Reporting\ReportingOAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;

/**
 * U3 (RP1): the connector's machine-facing endpoints, outside the session
 * and CSRF middleware: discovery metadata, client registration, the token
 * endpoint and the MCP endpoint itself.
 */
class ReportingConnectorController extends Controller
{
    public function __construct(private ReportingOAuth $oauth) {}

    public function protectedResource(): JsonResponse
    {
        return response()->json(ReportingOAuth::protectedResourceMetadata());
    }

    public function authorizationServer(): JsonResponse
    {
        return response()->json(ReportingOAuth::authorizationServerMetadata());
    }

    public function register(Request $request): JsonResponse
    {
        $result = $this->oauth->register($request->json()->all() ?: $request->all());

        return response()->json($result['body'], $result['ok'] ? 201 : 400, ['Cache-Control' => 'no-store']);
    }

    public function token(Request $request): JsonResponse
    {
        $result = $this->oauth->token($request->all());

        return response()->json($result['body'], $result['ok'] ? 200 : 400, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    public function mcp(Request $request, McpServer $server): Response|JsonResponse
    {
        $connection = $this->oauth->authenticate($request->bearerToken());
        if (! $connection) {
            $challenge = 'Bearer resource_metadata="'.route('reporting.metadata.resource').'", scope="'.ReportingOAuth::SCOPE.'"';
            if ($request->bearerToken()) {
                $challenge .= ', error="invalid_token"';
            }

            return response()->json(['error' => 'invalid_token', 'error_description' => 'Sign in with a One Smarter admin account to use the Flexee reporting connector.'], 401, ['WWW-Authenticate' => $challenge]);
        }

        $key = 'reporting-mcp:'.$connection->id;
        if (RateLimiter::tooManyAttempts($key, (int) config('flexee.reporting.calls_per_minute'))) {
            return response()->json(['error' => 'rate_limited'], 429, ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }
        RateLimiter::hit($key, 60);
        $connection->forceFill(['last_used_at' => now()])->saveQuietly();

        $message = json_decode($request->getContent(), true);
        if (! is_array($message) || array_is_list($message)) {
            return response()->json($server->error(null, -32700, 'Send one JSON-RPC message as a JSON object.'), 400);
        }
        $reply = $server->handle($message, $connection);

        return $reply === null ? response()->noContent(202) : response()->json($reply, 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function mcpGet(): Response
    {
        return response('This MCP endpoint answers POST requests only.', 405, ['Allow' => 'POST']);
    }
}
